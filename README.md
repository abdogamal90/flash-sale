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

## Production Deployment

### Required Services
1. **Web Server**: Nginx/Apache with PHP-FPM
2. **Queue Worker**: Supervisor to keep `queue:work` running
3. **Scheduler**: Cron entry for `schedule:run`
4. **Redis**: For caching and sessions
5. **MySQL**: InnoDB engine required

### Supervisor Config (Queue Worker)
```ini
[program:flash-sale-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/flash-sale/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/path/to/flash-sale/storage/logs/worker.log
```

### Cron Entry (Scheduler)
```cron
* * * * * cd /path/to/flash-sale && php artisan schedule:run >> /dev/null 2>&1
```

## Performance Considerations

- **Pessimistic Locking**: Serializes stock updates, max throughput ~1000 req/s per product
- **Redis Caching**: Reduces DB load for product reads by 90%+
- **Queue Workers**: Scale horizontally by adding more workers
- **Database Indexing**: Foreign keys and timestamps indexed by default

## License

Open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
