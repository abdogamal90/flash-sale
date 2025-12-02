# Flash Sale API

A high-concurrency Laravel API for managing flash sales with limited-stock products. Built to handle race conditions, prevent overselling, and support payment webhooks with idempotency.

## Features

- **Product Management**: Track total stock and available stock in real-time
- **Hold System**: Reserve stock for 2 minutes with automatic expiry
- **Concurrency Safety**: Pessimistic locking prevents overselling under high traffic
- **Auto-Expiry**: Hybrid system (delayed jobs + scheduled command) releases expired holds
- **Order Creation**: Convert holds to orders with validation and one-time use enforcement
- **Payment Webhooks**: Idempotent webhook handling for payment providers (Stripe-like)
- **Redis Caching**: Fast product reads with automatic cache invalidation on stock changes
- **Comprehensive Logging**: Structured logs for holds, releases, orders, and payments

## Architecture

### Database Schema
- **products**: `id`, `name`, `total_stock`, `available_stock`, `price`
- **holds**: `id`, `product_id`, `quantity`, `hold_expires_at`, `released_at`, `used_at`
- **orders**: `id`, `hold_id`, `status`, `payment_idempotency_key`

### Concurrency Strategy
- **Pessimistic Locking**: `SELECT ... FOR UPDATE` with database transactions
- **Stock Validation**: Inside locked transaction to prevent race conditions
- **Atomic Operations**: Stock decrement/increment happens in single transaction

### Expiry System
- **Primary**: Delayed job dispatched at hold creation, fires at exact expiry time
- **Backup**: Scheduled command runs every 5 minutes to catch missed jobs
- **Idempotent**: Both mechanisms safe to run concurrently

## Requirements

- PHP 8.2+
- MySQL 8.0+ (InnoDB engine for row-level locking)
- Redis 6.0+
- Composer 2.x

## Installation

### 1. Clone Repository
```bash
git clone https://github.com/abdogamal90/flash-sale.git
cd flash-sale
```

### 2. Install Dependencies
```bash
composer install
```

### 3. Environment Setup
```bash
cp .env.example .env
php artisan key:generate
```

### 4. Configure Database
Edit `.env`:
```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=flash_sale
DB_USERNAME=your_username
DB_PASSWORD=your_password

CACHE_STORE=redis
QUEUE_CONNECTION=database

REDIS_HOST=127.0.0.1
REDIS_PORT=6379
```

Create database:
```bash
mysql -u root -p
CREATE DATABASE flash_sale;
EXIT;
```

### 5. Run Migrations
```bash
php artisan migrate:fresh --seed
```

This creates a sample product with:
- Name: "Limited Edition Flash Sale Item"
- Total Stock: 200
- Available Stock: 100
- Price: $99.99

### 6. Start Services

**Terminal 1 - Web Server:**
```bash
php artisan serve
```

**Terminal 2 - Queue Worker (Required for expiry jobs):**
```bash
php artisan queue:work
```

**Terminal 3 - Scheduler (Optional, backup expiry):**
```bash
php artisan schedule:work
```

**Or use cron in production:**
```cron
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

## API Endpoints

### Products

**Get Product**
```bash
GET /api/products/{id}

# Example
curl http://localhost:8000/api/products/1
```

### Holds

**Create Hold** (Reserve stock for 2 minutes)
```bash
POST /api/holds
Content-Type: application/json

{
  "product_id": 1,
  "quantity": 5
}

# Example
curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id": 1, "quantity": 5}'
```

**Get All Holds**
```bash
GET /api/holds
```

**Get Specific Hold**
```bash
GET /api/holds/{id}
```

### Orders

**Create Order** (Convert hold to order)
```bash
POST /api/orders
Content-Type: application/json

{
  "hold_id": 1
}

# Example
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{"hold_id": 1}'
```

**Validation:**
- Hold must exist
- Hold must not be expired
- Hold must not be released
- Hold can only be used once

**Get All Orders**
```bash
GET /api/orders
```

**Get Specific Order**
```bash
GET /api/orders/{id}
```

### Payment Webhook

**Process Payment** (Simulates Stripe webhook)
```bash
POST /api/payments/webhook
Content-Type: application/json

{
  "event_id": "evt_unique_123",
  "order_id": 1,
  "status": "paid"  // or "failed"
}

# Example - Successful Payment
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d '{"event_id": "evt_123", "order_id": 1, "status": "paid"}'

# Example - Failed Payment
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d '{"event_id": "evt_456", "order_id": 2, "status": "failed"}'
```

**Features:**
- Idempotent: Same `event_id` processed only once
- Safe transitions: PENDING → COMPLETED or PENDING → CANCELLED
- Concurrent webhook handling with locking

## Testing Concurrency

Test that locking prevents overselling:

```bash
# Install GNU parallel (if not installed)
# Ubuntu/Debian: sudo apt install parallel
# macOS: brew install parallel

# Create 30 concurrent holds (10 units each = 300 total requested from 100 available)
seq 1 30 | parallel -j 30 'curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -d "{\"product_id\": 1, \"quantity\": 10}"'

# Expected: ~10 successful holds (100 stock / 10 units), remaining fail with "Insufficient stock"
```

## Complete Flow Example

```bash
# 1. Reset database
php artisan migrate:fresh --seed

# 2. Create hold (reserves 5 units for 2 minutes)
curl -X POST http://localhost:8000/api/holds \
  -H "Content-Type: application/json" \
  -d '{"product_id": 1, "quantity": 5}'
# Response: {"status":"success","data":{"hold_id":1,"hold_expires_at":"..."}}

# 3. Create order from hold
curl -X POST http://localhost:8000/api/orders \
  -H "Content-Type: application/json" \
  -d '{"hold_id": 1}'
# Response: {"status":"success","data":{"id":1,"hold_id":1,"status":"pending"}}

# 4. Simulate payment success
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d '{"event_id": "evt_abc123", "order_id": 1, "status": "paid"}'
# Response: {"status":"success","message":"Payment processed"}

# 5. Verify order status changed to "completed"
curl http://localhost:8000/api/orders/1

# 6. Try duplicate webhook (idempotency test)
curl -X POST http://localhost:8000/api/payments/webhook \
  -H "Content-Type: application/json" \
  -d '{"event_id": "evt_abc123", "order_id": 1, "status": "paid"}'
# Response: {"status":"success","message":"Webhook already processed"}
```

## Logging

Logs are stored in `storage/logs/laravel.log`:

```bash
# View recent logs
tail -f storage/logs/laravel.log

# Search for hold operations
grep "Hold" storage/logs/laravel.log

# Search for payment webhooks
grep "Payment webhook" storage/logs/laravel.log
```

**Logged Events:**
- Hold creation with product ID, quantity, expiry time
- Job dispatch for hold expiry
- Hold release (job and scheduled command)
- Order creation
- Payment webhook processing (success, duplicate, errors)

## Caching

Redis caching is enabled for product reads:

**How it works:**
- GET `/api/products/{id}` cached for 60 seconds
- Cache invalidated on stock changes (holds, releases)
- Subsequent reads served from Redis (fast)

**Check Redis cache:**
```bash
redis-cli
> KEYS product_*
> GET "laravel_database_product_1"
```

## Queues and Background Jobs

This application uses Laravel queues to handle hold expiry in the background.

### Why Queues?

When a hold is created, a job is dispatched to automatically release it after 2 minutes. Without queue workers, these jobs won't execute and holds won't be released automatically.

### Queue Configuration

The project uses **database** driver for queues (no additional setup needed):

```env
# .env
QUEUE_CONNECTION=database
```

Queue jobs are stored in the `jobs` table in the database.

### Running the Queue Worker

**Development (foreground):**
```bash
php artisan queue:work
```

Keep this running in a separate terminal. It will:
- Process `ReleaseExpiredHold` jobs at their scheduled time
- Listen for new jobs continuously
- Restart automatically on code changes (with `--timeout` flag)

**With options:**
```bash
# Process jobs with timeout and retries
php artisan queue:work --sleep=3 --tries=3 --max-time=3600

# Process specific queue
php artisan queue:work --queue=default

# Stop after processing all jobs
php artisan queue:work --stop-when-empty
```

### Queue Commands

**View failed jobs:**
```bash
php artisan queue:failed
```

**Retry failed jobs:**
```bash
# Retry specific job
php artisan queue:retry {job-id}

# Retry all failed jobs
php artisan queue:retry all
```

**Clear failed jobs:**
```bash
php artisan queue:flush
```

**Monitor queue in real-time:**
```bash
php artisan queue:monitor
```

### Scheduled Tasks (Scheduler)

The backup hold release command runs every 5 minutes via Laravel's scheduler.

**Development:**
```bash
php artisan schedule:work
```

### How Jobs Work in This Project

1. **Hold created** → `ReleaseExpiredHold` job dispatched with 2-minute delay
2. **Queue worker** processes job at scheduled time (2 min later)
3. **Job checks** if hold still needs release (not already released, not used for order)
4. **Stock restored** to product and hold marked as released
5. **Scheduled command** runs every 5 min to catch any missed jobs

**View dispatched jobs:**
```bash
# Check jobs table
mysql -u flash_sale -p flash_sale
SELECT * FROM jobs;
```
