# Foreground Queue for NativePHP Mobile

NativePHP Mobile provides a foreground queue system that processes Laravel jobs while the app is in use. This enables you to dispatch jobs using Laravel's familiar queue API without blocking the UI.

## Table of Contents

- [The Problem](#the-problem)
- [The Solution](#the-solution)
- [Quick Start](#quick-start)
- [Configuration](#configuration)
- [Creating Jobs](#creating-jobs)
- [Listening for Events](#listening-for-events)
- [How It Works](#how-it-works)
- [Best Practices](#best-practices)
- [Limitations](#limitations)

## The Problem

NativePHP Mobile runs PHP in a single-threaded embedded runtime:
- Only one PHP request can execute at a time
- Long-running operations (like API calls) block the UI
- Traditional Laravel queue workers (daemons) can't run

## The Solution

The `native` queue driver stores jobs in SQLite and processes them via a native coordinator. The coordinator runs jobs **between** UI interactions, ensuring your app stays responsive.

```php
// Dispatch a job - works exactly like Laravel
MyApiJob::dispatch($data);
```

Jobs run as separate PHP requests, orchestrated by the native layer (Swift/Kotlin), so they don't block the UI thread.

## Quick Start

### Step 1: Configure Queue Connection

Add to your `.env`:

```env
QUEUE_CONNECTION=native
```

Or update `config/queue.php`:

```php
'connections' => [
    'native' => [
        'driver' => 'native',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 60,
        'after_commit' => false,
    ],
    // ... other connections
],
```

### Step 2: Run Migrations

Ensure you have the jobs and failed_jobs tables:

```bash
php artisan queue:table
php artisan queue:failed-table
php artisan migrate
```

### Step 3: Dispatch Jobs

```php
use App\Jobs\SyncUserData;

// Dispatch a job
SyncUserData::dispatch($userId);

// With delay
SyncUserData::dispatch($userId)->delay(now()->addMinutes(5));

// On specific queue
SyncUserData::dispatch($userId)->onQueue('api-calls');
```

## Configuration

The native queue coordinator can be configured in `config/nativephp.php`:

```php
'queue' => [
    // Minimum delay between job processing (milliseconds)
    'min_delay' => 100,
    
    // How often to poll when queue is empty (milliseconds)
    'poll_interval' => 2000,
    
    // Maximum jobs per batch before yielding to UI
    'batch_size' => 10,
],
```

## Creating Jobs

Create jobs exactly as you would in Laravel:

```php
<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class SyncUserData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $userId
    ) {}

    public function handle(): void
    {
        $user = User::find($this->userId);
        
        // Make API call - this works perfectly in a job!
        $response = Http::withToken(config('services.api.token'))
            ->post('https://api.example.com/sync', [
                'user_id' => $user->id,
                'data' => $user->toArray(),
            ]);

        if ($response->failed()) {
            throw new \Exception('Sync failed: ' . $response->body());
        }
        
        $user->update(['synced_at' => now()]);
    }

    public function failed(\Throwable $e): void
    {
        // Handle failure
        logger()->error('SyncUserData failed', [
            'user_id' => $this->userId,
            'error' => $e->getMessage(),
        ]);
    }
}
```

### Making HTTP Calls in Jobs

Jobs can use Laravel's HTTP client for API calls:

```php
class FetchExternalData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        // GET request
        $response = Http::get('https://api.example.com/data');
        
        // POST with authentication
        $response = Http::withToken($token)
            ->post('https://api.example.com/users', [
                'name' => 'John',
            ]);
        
        // Handle response
        if ($response->successful()) {
            $data = $response->json();
            // Process $data...
        }
    }
}
```

## Listening for Events

Track job completion/failure in your Livewire components:

```php
<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\Attributes\On;
use App\Jobs\SyncUserData;

class UserProfile extends Component
{
    public bool $syncing = false;
    public ?string $error = null;
    public ?string $lastSyncedAt = null;

    public function sync()
    {
        $this->syncing = true;
        $this->error = null;
        
        SyncUserData::dispatch(auth()->id());
    }

    #[On('native:Native\Mobile\Queue\Events\JobCompleted')]
    public function onJobCompleted($jobId, $jobName, $queue, $durationMs)
    {
        // Check if this is our job
        if ($jobName === SyncUserData::class) {
            $this->syncing = false;
            $this->lastSyncedAt = now()->toDateTimeString();
        }
    }

    #[On('native:Native\Mobile\Queue\Events\JobFailed')]
    public function onJobFailed($jobId, $jobName, $error, $willRetry, $attempts)
    {
        if ($jobName === SyncUserData::class) {
            $this->syncing = false;
            
            if (!$willRetry) {
                $this->error = $error;
            }
        }
    }

    #[On('native:Native\Mobile\Queue\Events\QueueEmpty')]
    public function onQueueEmpty($queue, $processedCount)
    {
        // All queued jobs finished
    }
}
```

### Available Events

| Event | Payload |
|-------|---------|
| `JobCompleted` | `jobId`, `jobName`, `queue`, `durationMs` |
| `JobFailed` | `jobId`, `jobName`, `error`, `willRetry`, `attempts` |
| `QueueEmpty` | `queue`, `processedCount` |

## How It Works

```
┌─────────────────────────────────────────────────────────────────┐
│  PHP: MyJob::dispatch($data)                                    │
│       └── Job serialized & stored in SQLite                     │
│       └── Native coordinator notified via bridge                │
│       └── Returns immediately (non-blocking)                    │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Native Queue Coordinator (Swift/Kotlin)                        │
│  - Monitors job count                                           │
│  - During idle time, calls: POST /_native/queue/work            │
│  - Throttles to avoid blocking UI                               │
│  - Processes one job per request                                │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  PHP: /_native/queue/work                                       │
│  - Pops ONE job from database                                   │
│  - Executes job->handle()                                       │
│  - Returns result + remaining count                             │
└─────────────────────────────────────────────────────────────────┘
                              │
                              ▼
┌─────────────────────────────────────────────────────────────────┐
│  Events dispatched to WebView/Livewire                          │
│  - JobCompleted / JobFailed                                     │
│  - Coordinator schedules next job                               │
└─────────────────────────────────────────────────────────────────┘
```

Key points:
- Jobs stored in SQLite (survives app restart)
- Native layer orchestrates processing
- Jobs run as separate PHP "requests" (not blocking UI requests)
- One job at a time with configurable throttling

## Best Practices

### 1. Keep Jobs Small

```php
// Good - focused job
class UpdateUserAvatar implements ShouldQueue
{
    public function handle()
    {
        $this->uploadAvatar();
        $this->updateDatabase();
    }
}

// Bad - too much in one job
class DoEverything implements ShouldQueue
{
    public function handle()
    {
        $this->syncUsers();
        $this->processOrders();
        $this->generateReports();
        $this->sendEmails();
    }
}
```

### 2. Handle Failures Gracefully

```php
class MyJob implements ShouldQueue
{
    public $tries = 3;
    public $backoff = [30, 60, 120]; // Seconds between retries

    public function handle()
    {
        // Job logic
    }

    public function failed(\Throwable $e)
    {
        // Notify user, log, etc.
    }
}
```

### 3. Show Progress for Long Operations

```php
// Dispatch multiple smaller jobs
foreach ($items->chunk(10) as $index => $chunk) {
    ProcessChunk::dispatch($chunk, $index, $items->count());
}

// Track progress via events
#[On('native:Native\Mobile\Queue\Events\JobCompleted')]
public function onJobComplete($jobId, $jobName)
{
    if (str_starts_with($jobName, 'ProcessChunk')) {
        $this->completedChunks++;
        $this->progress = ($this->completedChunks / $this->totalChunks) * 100;
    }
}
```

### 4. Use Job Batching for Related Work

```php
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;

Bus::batch([
    new ProcessPodcast($podcast),
    new GenerateThumbnail($podcast),
    new NotifySubscribers($podcast),
])->dispatch();
```

## Limitations

### Foreground Only
- Jobs only process while app is in foreground
- Processing pauses when app backgrounds
- Jobs persist and resume when app returns

### No True Background Processing
- Can't run jobs while app is closed
- For critical background work, use server-side queues
- Consider push notifications to prompt user to open app

### Single-Threaded Execution
- Jobs run one at a time
- Each job blocks other jobs (not UI)
- Keep jobs fast for better throughput

### Comparison with Server Queues

| Feature | NativePHP Queue | Server Queue |
|---------|-----------------|--------------|
| Works offline | ✅ | ❌ |
| Background processing | ❌ (foreground only) | ✅ |
| Persistent jobs | ✅ (SQLite) | ✅ (Redis/DB) |
| Retry/failure | ✅ | ✅ |
| Multiple workers | ❌ | ✅ |
| No server required | ✅ | ❌ |

### Recommended Hybrid Architecture

For the best of both worlds, use local queue for immediate work and server queue for critical processing:

```php
// Local queue - for UI responsiveness
UpdateLocalCache::dispatch($data);

// Server queue - via API call in a job
class SyncToServer implements ShouldQueue
{
    public function handle()
    {
        Http::post('https://api.myserver.com/queue/critical-job', [
            'type' => 'process_payment',
            'data' => $this->data,
        ]);
    }
}
```
