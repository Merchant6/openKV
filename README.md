# OpenKV

OpenKV is a Redis-inspired in-memory key-value database written in PHP with OpenSwoole.

The project is intentionally small, but it is built like infrastructure software: a long-lived TCP daemon, event-driven network callbacks, shared-memory storage, deterministic command handling, TTL expiration, and observable runtime metrics.

This is not a Laravel application and it is not a Redis replacement. The goal is systems understanding: TCP servers, long-running PHP runtimes, OpenSwoole worker lifecycle, memory-backed storage, command parsing, and operational visibility.

## Requirements

- PHP 8.3+
- OpenSwoole extension
- Composer
- `nc` for manual TCP testing

Install dependencies:

```bash
composer install
```

Check that OpenSwoole is loaded:

```bash
php -m | grep openswoole
```

## Start The Server

Start OpenKV on the default address:

```bash
php bin/swoole-kv server:start
```

Defaults:

```txt
host: 127.0.0.1
port: 9501
```

Use a custom host or port:

```bash
php bin/swoole-kv server:start --host=127.0.0.1 --port=9601
```

## Connect With nc

In another terminal:

```bash
nc 127.0.0.1 9501
```

The server sends a connection banner:

```txt
+OK OpenKV connected
```

Commands are plain text and line-oriented:

```txt
PING
SET name Saboor
GET name
```

## Supported Commands

### PING

```txt
PING
+PONG
```

With one argument, `PING` echoes that argument as a bulk string:

```txt
PING hello
$5
hello
```

### SET and GET

```txt
SET name Saboor
+OK

GET name
$6
Saboor
```

Missing keys return a null bulk string:

```txt
GET missing
$-1
```

### DEL and EXISTS

```txt
SET name Saboor
+OK

EXISTS name
:1

DEL name
:1

EXISTS name
:0
```

### EXPIRE and TTL

Set a TTL in seconds:

```txt
SET session abc123
+OK

EXPIRE session 5
:1

TTL session
:5
```

After the key expires:

```txt
GET session
$-1

TTL session
:-2
```

TTL response meanings:

- `-2`: key does not exist or has expired
- `-1`: key exists with no expiration
- `>= 0`: remaining lifetime in seconds

### INCR and DECR

Missing keys start at zero:

```txt
INCR count
:1

DECR count
:0
```

Existing integer values are mutated in place:

```txt
SET count 10
+OK

INCR count
:11

DECR count
:10
```

Non-integer values are rejected:

```txt
SET name Saboor
+OK

INCR name
-ERR Value for key 'name' is not an integer.
```

### INFO

`INFO` returns a multi-section runtime report:

```txt
INFO
$267
# Server
openkv_version:0.1.0
uptime_seconds:10
worker_count:1

# Clients
connections_handled:1
active_connections:1

# Stats
commands_processed:4
requests_per_second:0.40
expired_keys:0

# Storage
total_keys:2

# Memory
memory_usage_bytes:4194304
```

### STATS

`STATS` returns a compact counter list:

```txt
STATS
$140
commands_processed:3
total_keys:2
expired_keys:0
uptime_seconds:10
requests_per_second:0.30
connections_handled:1
active_connections:1
```

## Testing

Run the test suite:

```bash
composer test
```

The TCP integration tests start the real server on an available local port, connect over TCP, send commands, assert responses, and terminate the server process.

Run PHPUnit directly:

```bash
vendor/bin/phpunit
```

Run static analysis:

```bash
vendor/bin/phpstan analyse src tests --level=5 --no-progress
```

## Project Structure

```txt
bin/
  swoole-kv
src/
  Command/
  Console/
  Metrics/
  Server/
  Storage/
  Timer/
tests/
  Integration/
```

Key boundaries:

- `CommandParser` turns raw TCP input into parsed command objects.
- `CommandHandler` validates command arguments and formats protocol responses.
- `KeyValueStore` defines the storage boundary.
- `SwooleTableStore` stores values and expiration metadata in `Swoole\Table`.
- `ServerEventHandler` owns OpenSwoole server event callbacks.
- `ExpirationTimer` centralizes background TTL cleanup.
- `ServerMetrics` tracks runtime counters for `INFO` and `STATS`.

## Design Essay

OpenKV is built around one central idea: PHP can be used to study infrastructure systems when it is run as a long-lived event-driven process instead of as a short-lived request script.

OpenSwoole provides the runtime shape for that experiment. The server is not a loop written by hand around blocking socket calls. It is an event-driven TCP daemon where OpenSwoole owns the socket lifecycle and calls PHP code when clients connect, send data, or disconnect. That keeps networking concerns explicit while still letting the application code stay small and readable.

The storage engine uses `Swoole\Table` because the project is about runtime behavior, not just command syntax. `Swoole\Table` stores data in shared memory, which makes it a better fit for learning about long-running workers and shared state than a normal PHP array. The current store records both the value and expiration timestamp for each key. Reads, existence checks, deletes, and numeric mutations all treat expired keys as missing, so expiration is part of storage semantics rather than a separate afterthought.

Command execution is intentionally synchronous inside each worker callback. That is acceptable here because commands operate on memory and return quickly. The async part of the system is the TCP server and event dispatch model, not coroutine-based command execution. If a future command performs blocking IO, it should either use OpenSwoole coroutine-aware clients or be isolated so it does not block a worker.

The protocol is deliberately RESP-like but simplified. Simple strings, integers, errors, bulk strings, and null bulk strings are enough to make behavior inspectable with `nc` while leaving room for future RESP compatibility. The command layer validates argument counts and numeric input before touching storage, so malformed client input produces deterministic errors instead of corrupting state.

Metrics are kept as a first-class runtime concern. `INFO` and `STATS` expose command counts, live keys, expired keys, uptime, memory usage, connections, and worker count. These numbers are not meant to claim production-grade performance. They exist so the server can be observed while it runs and so future benchmark work has concrete counters to compare against.

The codebase is intentionally phased. Each commit introduces one coherent capability: server bootstrap, parsing, storage, core commands, TTL, numeric mutation, observability, and TCP integration tests. That history matters because the project is educational. The goal is not only to end up with a small key-value server, but to show how such a system grows from explicit boundaries and runtime responsibilities.

## Current Limitations

- Persistence is not implemented; all data is in memory.
- The protocol is RESP-like, not fully RESP-compatible.
- `SET` currently accepts exactly `SET key value`; options like `EX`, `PX`, `NX`, and `XX` are not implemented.
- `DEL` and `EXISTS` accept one key at a time.
- Metrics are process-local and simple.
- Authentication, TLS, replication, pub/sub, streams, and snapshots are not implemented.
- Benchmark tooling is planned but not implemented yet.

## Roadmap

Near-term improvements:

- Usage-focused documentation examples for more command flows
- Benchmark command: `php bin/swoole-kv benchmark`
- Stats command: `php bin/swoole-kv stats`
- Graceful stop command: `php bin/swoole-kv server:stop`
- More deterministic unit tests for parser, storage, TTL, and metrics
- RESP compatibility experiments
- Persistence snapshot experiments
