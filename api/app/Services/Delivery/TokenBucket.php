<?php

namespace App\Services\Delivery;

use Illuminate\Support\Facades\Redis;

/**
 * Per-destination token bucket in Redis, refilled continuously at `rps` tokens/second
 * with a burst capacity of `rps` (minimum 1). The check-and-take runs as one Lua script,
 * so concurrent workers cannot overspend the bucket.
 */
class TokenBucket
{
    private const SCRIPT = <<<'LUA'
local key = KEYS[1]
local rate = tonumber(ARGV[1])
local capacity = tonumber(ARGV[2])
local now_ms = tonumber(ARGV[3])

local state = redis.call('HMGET', key, 'tokens', 'ts')
local tokens = tonumber(state[1])
local ts = tonumber(state[2])
if tokens == nil then
  tokens = capacity
  ts = now_ms
end

local elapsed = math.max(0, now_ms - ts)
tokens = math.min(capacity, tokens + elapsed * rate / 1000)

local allowed = 0
if tokens >= 1 then
  tokens = tokens - 1
  allowed = 1
end

redis.call('HSET', key, 'tokens', tostring(tokens), 'ts', tostring(now_ms))
redis.call('PEXPIRE', key, math.ceil(capacity / rate * 1000) + 1000)
return allowed
LUA;

    public function take(string $destinationId, int $rps, ?int $nowMs = null): bool
    {
        if ($rps <= 0) {
            return true;
        }

        $nowMs ??= (int) floor(microtime(true) * 1000);

        return (int) Redis::connection()->eval(
            self::SCRIPT,
            1,
            "relay:ratelimit:{$destinationId}",
            $rps,
            max(1, $rps),
            $nowMs,
        ) === 1;
    }
}
