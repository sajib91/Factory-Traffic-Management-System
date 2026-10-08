# Factory Traffic Management System

## Setup

```sh
composer install
cp .env.example .env
php artisan key:generate
# Set DB_CONNECTION=mysql, DB_DATABASE=factory_traffic, DB_USERNAME and DB_PASSWORD in .env
php artisan migrate
php artisan db:seed
php artisan serve
php artisan traffic:run
```

Open `http://127.0.0.1:8001/`. The dashboard is for junction `A`; the API is under `/api`.

## Demo Scenarios

1. **Normal:** send a `VEHICLE_ARRIVED` event, wait for the automatic tick, ACK the pending command, then clear it.
2. **Priority:** queue a truck and verify the scheduler selects the weighted/oldest phase.
3. **Emergency:** send `vehicle_type: EMERGENCY`; observe preemption, ACK transitions, then clear the emergency vehicle.
4. **Manual:** use Green N/S or Green E/W, then Return to Automatic.
5. **Duplicate:** resend the same `event_id`; the first response is `201`, the duplicate is idempotent `200`.
6. **Clearance:** send arrival then matching clear; a clear without an arrival returns `422`.
7. **Controller failure:** set the controller `OFFLINE`; the junction becomes DEGRADED and requests ALL_RED.
8. **Restart:** stop and restart `traffic:run`; recovery marks actual signals UNKNOWN, supersedes pending commands, sends ALL_RED, and waits for its ACK.
9. **Concurrent:** send events for one junction concurrently; row locking and sequence cursors serialize them.

## API

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/api/junctions` | List junctions |
| POST | `/api/junctions` | Create a junction |
| GET | `/api/junctions/{id}` | Read complete junction state |
| GET | `/api/junctions/{id}/status` | Read live status: desired/actual signals, queues, mode, phase, controller, emergency, manual, pending command, alerts |
| POST | `/api/sensor-events` | Idempotent arrival/clear event |
| POST | `/api/junctions/{id}/commands` | Manual green or return to automatic |
| POST | `/api/controller-events` | ACK or controller ONLINE/OFFLINE event |
| GET | `/api/junctions/{id}/history?limit=50&type=` | Audit history |

Responses use `{"data": ...}`. Errors use `{"error":{"code":"...","message":"...","details":...}}`. Status codes are `201` for creation/new events, `200` for duplicate events, `202` for accepted commands/controller events, `404` for an unknown junction, `409` for a command forbidden by mode, and `422` for invalid input or event state.

## Scheduling

The engine keeps a per-junction phase timer and queue snapshot. It weights vehicle classes, considers oldest waiting time, enforces minimum green, yellow, and all-red intervals, and only permits configured non-conflicting greens. Green commands are ACK-gated; timeouts retry and then degrade to ALL_RED.

```text
AUTOMATIC -> YELLOW -> ALL_RED -> GREEN -> YELLOW -> ALL_RED
     |          ^          ^         |
     |          |          |         |
  EMERGENCY ----+       MANUAL <----+
     |                         |
     +--------> AUTOMATIC <-----+

any controller failure -> DEGRADED -> ALL_RED ACK -> AUTOMATIC
restart -> DEGRADED/ALL_RED -> recovery ACK -> AUTOMATIC
```

## Consistency and Recovery

All writes for a junction run through `TrafficService` in a database transaction with `lockForUpdate()`. Sensor event IDs are stored in `processed_events`; duplicate delivery is harmless. Queues, processed events, and audit history are never reset. `traffic:recover` sets actual signals to UNKNOWN, marks pending controller commands SUPERSEDED, sets DEGRADED and desired ALL_RED, persists a new command, and writes `RECOVERY_STARTED`. Its ACK resets the ALL_RED step timer and resumes AUTOMATIC.

## Assumptions / Questions / Requirement Issues

- Commands currently address a phase through `GREEN_NS` or `GREEN_EW`; confirm whether the contract requires per-direction commands or per-junction phase commands.
- `occurred_at` is interpreted as an ISO/date timestamp and stale events older than the configured window are rejected.
- Emergency preemption takes precedence over manual control; manual commands are rejected during EMERGENCY and DEGRADED.
- A conflict means more than one configured phase has GREEN signals at once; EAST/WEST are one configured phase.
- ACK timeout is 10 seconds, with two retries, then DEGRADED.
- Requirement contradiction: “emergency EAST” implies EAST/WEST green, but the conflict wording also says both EAST and WEST turn green together. This implementation follows the configured EAST_WEST phase.
- Manual-expiry behavior is present in the engine, but the required external expiry policy is unspecified; the configured manual override expiry is used.

## Architecture Decisions

Controllers are thin HTTP adapters. FormRequests own validation, application services own orchestration, the domain engine owns sequencing, Eloquent models own persistence, and the Blade page only renders API state. Polling was chosen for a small local operator view; it is simple, robust, and adequate for two-second freshness.

## AI / Tool Usage

AI assistance was used to inspect the existing Laravel/domain code, preserve its service boundary, implement API adapters and recovery behavior, generate focused tests and documentation, and run the PHP test suite. Changes were checked against the existing traffic tests and the restart recovery test.

## What I would do next

Add an MQTT controller adapter, authentication and authorization for manual control, SSE for larger deployments, Docker/XAMPP-independent setup, event replay tooling, and metrics for queue age, ACK latency, retries, and degraded time.

## Assessment Coverage

Normal, priority, emergency, manual, duplicate, clearance, controller failure, restart, and concurrent scenarios are covered by the feature/unit tests and the demo steps above. The restart test specifically asserts that no GREEN is desired before the recovery ACK.
