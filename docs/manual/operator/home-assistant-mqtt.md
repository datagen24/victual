# Home Assistant and MQTT

Victual can push the household's ambient state to an MQTT broker as retained topics, with
Home Assistant discovery payloads alongside them, so nothing has to poll: a consumer holds
the last snapshot across its own restarts and across the server being asleep or gone
entirely. Off by default — set `MQTT_ENABLED` and the `MQTT_*` settings
([Configuration](../configuration.md#home-assistant-and-mqtt)).

## What gets published

Deliberately narrow: only facts — dates and counts — never a derived state like "expiring
soon" or "overdue", because a derived value is a function of the clock and would need
something awake to recompute it. Anything holding broker credentials can read these topics
without authenticating to Victual, so **no price, cost, value, note or user record is ever
published here**; that is InfluxDB's job, below.

Seven summary sensors publish by default. A product also gets its own sensor once you opt it
in — `POST /api/objects/mqtt_product_entities` with `{"product_id": <id>}`, and `DELETE` to
remove it, which retracts that product's topics.

## Keeping the broker in sync

Run `bin/victual-publish-state` once after every deployment — a postStart hook, or a Job
alongside the `bin/victual-migrate` initContainer. It republishes the discovery payloads and
the full snapshot, which is what makes a fresh deployment, a migration, a data import or a
manual change in `psql` visible on the broker instead of silently drifting from what it
still holds. `bin/victual-publish-state --retract` clears every retained topic this version
owns — use it when decommissioning the integration.

Beyond that one command, publishing happens automatically at the end of any request that
changed data, after the response is finished. `MQTT_CONNECT_TIMEOUT_SECONDS` bounds how long
an unreachable broker can delay that; a publish failure is logged and never reaches (or
rolls back) the write that triggered it. The delay sits off the response under php-fpm and
on it under mod_php, so on mod_php an unreachable broker costs the caller that timeout.

## InfluxDB

`INFLUXDB_ENABLED` and the `INFLUXDB_*` settings
([Configuration](../configuration.md#influxdb)) write price and stock-value *events* — not
sampled state — to InfluxDB on the same after-commit path: a point when a purchase commits
produces a series whose gaps mean "no purchases", which is true, where sampling stock from a
pod that is mostly asleep would produce gaps that mean nothing. Two measurements, both
tagged only with `product_id` and carrying no user-identifying data:

```
price_paid,product_id=<id>  price=<paid>,amount=<booked>    at the booking's own timestamp
stock_value,product_id=<id> value=<worth>,amount=<in stock> at the end of the request
```

This is where "how has spending shifted" gets answered, precisely because it is queried
with its own credentials rather than broadcast to anything on the broker. `INFLUXDB_TIMEOUT_SECONDS`
bounds the same kind of delay `MQTT_CONNECT_TIMEOUT_SECONDS` does, and a write failure is
handled the same way: logged, never reaching the booking that triggered it.
