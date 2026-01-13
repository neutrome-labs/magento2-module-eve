# Neutrome Eve - Magento 2 Fraud Detection Module

A Magento 2 module that integrates with the [Eve fraud detection system](https://github.com/neutrome-labs/eve). It intercepts Magento events and system logs, sending them to Eve for ML-powered anomaly detection.

## Features

- **Event Collector**: Plugin on `Magento\Framework\Event\Manager` captures Magento events before observers run
- **Logs Collector**: Plugin on Logger captures error/warning logs for anomaly detection
- **Customer Group Scores**: Configure `input_score` per customer group for flexible training/production modes
- **RSS Feed Polling**: Polls Eve feed for `bad_user` notifications (no webhooks needed)
- **Auto-Block**: Automatically blocks customers flagged by Eve
- **Configurable Collectors**: Enable/disable events and logs collectors independently

## Installation

### Option 1: Composer (Recommended)

```bash
composer require neutrome-labs/module-eve
bin/magento module:enable NeutromeLabs_Eve
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Option 2: Manual Installation

```bash
# Copy module files
cp -r app/code/NeutromeLabs /path/to/magento/app/code/

# Enable module
bin/magento module:enable NeutromeLabs_Eve

# Run setup
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

## Configuration

Navigate to **Stores → Configuration → Neutrome → Eve Fraud Detection**

### General Settings
| Setting | Description |
|---------|-------------|
| **Enable** | Turn the module on/off |

### Data Collectors
| Setting | Description |
|---------|-------------|
| **Events Collector** | Collect Magento events (orders, cart, customer actions) |
| **Logs Collector** | Collect system logs (errors, warnings) for anomaly detection |
| **Customer Group Scores** | Table to set `input_score` per customer group |

### Customer Group Scores

Configure how events from each customer group are scored:

| Input Score | Meaning | Use Case |
|-------------|---------|----------|
| **Empty** | ML scoring mode | Production - let ML score events |
| **0.0** | Good/normal | Training baseline - known good customers |
| **1.0** | Bad/anomaly | Flagged groups - known bad actors |
| **0.0-1.0** | Custom weight | Partial trust levels |

Example configuration:
| Customer Group | Input Score | Description |
|----------------|-------------|-------------|
| General | *(empty)* | ML scores these events |
| VIP | 0.0 | Training baseline (trusted) |
| Suspicious | 1.0 | Flag as anomalies |
| NOT LOGGED IN | *(empty)* | ML scores guest events |

### API Configuration
| Setting | Description |
|---------|-------------|
| **Vector Endpoint** | Vector ingest URL (e.g., `http://eve-host:8080/ingest`) |
| **GraphQL Endpoint** | Hasura GraphQL URL for feed queries |
| **Hasura Secret** | Admin secret for GraphQL authentication |
| **Series Name** | Unique identifier for this Magento instance |
| **Timeout** | API request timeout in seconds |

### Feed Configuration
| Setting | Description |
|---------|-------------|
| **Enable Polling** | Poll Eve feed via cron (every 5 minutes) |
| **Auto-Block** | Automatically block customers on `bad_user` feed entries |
| **Block Threshold** | Minimum score (0.0-1.0) to trigger blocking |
| **Notify Admin** | Send admin notification when blocking |

## How It Works

### Event Collection Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                        MAGENTO                                   │
│  ┌─────────────┐    ┌──────────────────┐    ┌───────────────┐   │
│  │ Any Module  │───▶│ Event Manager    │───▶│ Observers     │   │
│  │ dispatch()  │    │ beforeDispatch() │    │ (normal flow) │   │
│  └─────────────┘    └────────┬─────────┘    └───────────────┘   │
│                              │                                   │
│                    ┌─────────▼─────────┐                        │
│                    │ EventManagerPlugin│                        │
│                    │ (intercepts ALL   │                        │
│                    │  events BEFORE    │                        │
│                    │  observers)       │                        │
│                    └─────────┬─────────┘                        │
└──────────────────────────────┼──────────────────────────────────┘
                               │
                    ┌──────────▼──────────┐
                    │ Eve Vector          │
                    │ POST /ingest        │
                    │ {                   │
                    │   series_name,      │
                    │   user_id,          │
                    │   data_payload,     │
                    │   input_score       │ ← Based on customer group
                    │ }                   │
                    └─────────────────────┘
```

### Logs Collection Flow

```
┌─────────────────────────────────────────────────────────────────┐
│                        MAGENTO                                   │
│  ┌─────────────┐    ┌──────────────────┐    ┌───────────────┐   │
│  │ Any Code    │───▶│ Logger           │───▶│ Log Handlers  │   │
│  │ ->error()   │    │ (Monolog)        │    │ (file, etc)   │   │
│  └─────────────┘    └────────┬─────────┘    └───────────────┘   │
│                              │                                   │
│                    ┌─────────▼─────────┐                        │
│                    │ LoggerPlugin      │                        │
│                    │ (intercepts       │                        │
│                    │  error/warning    │                        │
│                    │  level logs)      │                        │
│                    └─────────┬─────────┘                        │
└──────────────────────────────┼──────────────────────────────────┘
                               │
                    ┌──────────▼──────────┐
                    │ Eve Vector          │
                    │ event_name:         │
                    │   system_log_error  │
                    │   system_log_warning│
                    └─────────────────────┘
```

### RSS Feed Polling (Incoming)

```
┌─────────────────┐         ┌─────────────────┐
│ Eve Feed Table  │◀────────│ Magento Cron    │
│ feed_entries    │  poll   │ PollFeed        │
│                 │ GraphQL │ (every 5 min)   │
└─────────────────┘         └────────┬────────┘
                                     │
                            ┌────────▼────────┐
                            │ FeedProcessor   │
                            │ 1. Query feed   │
                            │ 2. Check score  │
                            │ 3. Block user   │
                            └─────────────────┘
```

### Feed Entry Types

| Entry Type | Handler Action |
|------------|----------------|
| `bad_user` | Block customer if score ≥ threshold |
| `bad_event` | Log event details |
| `training_started` | Log info |
| `training_completed` | Log info |
| `training_failed` | Log warning |

## Event Payload Example

```json
{
  "series_name": "magento2_store",
  "user_id": "customer_123",
  "data_payload": {
    "event_name": "sales_order_place_after",
    "event_type": "event",
    "payload": {
      "order": {
        "__class": "Magento\\Sales\\Model\\Order",
        "data": {
          "entity_id": "1234",
          "increment_id": "100000123",
          "grand_total": 99.99,
          "customer_email": "customer@example.com"
        }
      }
    },
    "store_id": 1
  },
  "happened_at": "2026-01-11T10:30:00+00:00",
  "input_score": 0.0
}
```

## Log Event Payload Example

```json
{
  "series_name": "magento2_store",
  "user_id": "customer_123",
  "data_payload": {
    "event_name": "system_log_error",
    "event_type": "log",
    "payload": {
      "log_level": "error",
      "message": "Payment gateway timeout after 30 seconds",
      "context": {
        "order_id": "1234",
        "gateway": "stripe"
      }
    },
    "store_id": 1
  },
  "happened_at": "2026-01-11T10:30:00+00:00"
}
```

## Tracked Events

The plugin tracks these event patterns by default:

| Pattern | Examples |
|---------|----------|
| `customer_*` | Login, logout, registration, address changes |
| `sales_order_*` | Order placement, status changes, refunds |
| `checkout_cart_*` | Add to cart, update qty, remove item |
| `catalog_product_*` | Product views |
| `wishlist_*` | Wishlist additions, removals |
| `review_*` | Review submissions |

Internal/noisy events (layout, blocks, cache, model_load) are automatically filtered.

## Feed Polling

The module polls Eve's RSS feed via cron (every 5 minutes) or CLI:

```bash
# Poll feed manually
bin/magento eve:feed:poll --limit=50
```

### Feed Query Example

The module queries Eve via GraphQL:

```graphql
query GetFeedEntries($series: String!, $since: timestamptz!) {
  feed_entries(
    where: {
      series_name: { _eq: $series }
      published_at: { _gt: $since }
    }
    order_by: { published_at: asc }
    limit: 100
  ) {
    id
    entry_type
    title
    content
    published_at
  }
}
```

## Files Structure

```
app/code/NeutromeLabs/Eve/
├── etc/
│   ├── module.xml              # Module declaration
│   ├── di.xml                  # Dependency injection & plugin config
│   ├── config.xml              # Default configuration values
│   ├── acl.xml                 # Admin ACL resources
│   ├── crontab.xml             # Cron job definitions
│   └── adminhtml/
│       └── system.xml          # Admin configuration UI
├── Block/
│   └── Adminhtml/Form/Field/
│       ├── CustomerGroupScores.php   # Dynamic rows field
│       └── CustomerGroupColumn.php   # Customer group dropdown
├── Model/
│   ├── Config.php              # Configuration provider
│   └── Config/Backend/
│       └── CustomerGroupScores.php   # Backend model for scores table
├── Plugin/
│   ├── EventManagerPlugin.php  # Event interceptor
│   └── LoggerPlugin.php        # Logger interceptor
├── Service/
│   ├── ApiClient.php           # Eve API client (sends to Vector)
│   ├── FeedProcessor.php       # RSS feed processor
│   └── CustomerBlockService.php # Customer blocking logic
├── Console/Command/
│   └── PollFeedCommand.php     # CLI command for feed polling
├── Cron/
│   └── PollFeed.php            # Cron job for feed polling
├── registration.php            # Module registration
└── composer.json               # Composer package definition
```

## Troubleshooting

### Events Not Sending

1. Check if module is enabled: `bin/magento module:status NeutromeLabs_Eve`
2. Verify Events Collector is enabled in config
3. Verify API endpoint is reachable from Magento server
4. Check Magento logs: `var/log/neutrome_eve.log`

### Logs Not Being Collected

1. Verify Logs Collector is enabled in config
2. Only error, warning, critical, alert, emergency levels are tracked
3. Internal Eve logs are automatically skipped to prevent recursion

### Customer Not Being Blocked

1. Verify Feed Polling is enabled in config
2. Verify Auto-Block is enabled in config
3. Check Block Threshold - score must be ≥ threshold
4. Ensure customer exists in Magento with matching `user_id`
5. Check feed poll logs: `bin/magento eve:feed:poll -v`

### Customer Group Scores Not Working

1. Ensure you saved the config after adding rows
2. Customer group ID 0 = "NOT LOGGED IN" (guests)
3. Empty input_score means ML scoring mode (no score sent)

## License

MIT
