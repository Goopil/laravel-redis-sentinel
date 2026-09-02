## [1.6.2](https://github.com/Goopil/laravel-redis-sentinel/compare/1.6.1...1.6.2) (2026-09-02)


### Bug Fixes

* match Horizon master supervisor names by hostname basename with delimiter ([#80](https://github.com/Goopil/laravel-redis-sentinel/issues/80)) ([2f9fc25](https://github.com/Goopil/laravel-redis-sentinel/commit/2f9fc25764ca7d099b3cf5162bbd3a2d9f1a5791))
* tighten default redis retry messages to specific transient failures ([#79](https://github.com/Goopil/laravel-redis-sentinel/issues/79)) ([ffb26e5](https://github.com/Goopil/laravel-redis-sentinel/commit/ffb26e5f2de5de02ad1ac7bf5fd9f14cdd735b68))

## [1.6.1](https://github.com/Goopil/laravel-redis-sentinel/compare/1.6.0...1.6.1) (2026-09-02)


### Bug Fixes

* neutralize parent command() retry and reconnect side effects ([#78](https://github.com/Goopil/laravel-redis-sentinel/issues/78)) ([e3a1320](https://github.com/Goopil/laravel-redis-sentinel/commit/e3a1320a44df1fcd863ba2bbcdc1246c8703141a))

# [1.6.0](https://github.com/Goopil/laravel-redis-sentinel/compare/1.5.2...1.6.0) (2026-09-02)


### Features

* surface the no-healthy-replica master fallback with a warning log and event ([#77](https://github.com/Goopil/laravel-redis-sentinel/issues/77)) ([2ac5a2b](https://github.com/Goopil/laravel-redis-sentinel/commit/2ac5a2b9b0d969cec9e93ba97d01d821b9c82604))

## [1.5.2](https://github.com/Goopil/laravel-redis-sentinel/compare/1.5.1...1.5.2) (2026-09-02)


### Bug Fixes

* fail fast when all sentinels are unreachable (~30s stall per command) ([#74](https://github.com/Goopil/laravel-redis-sentinel/issues/74)) ([7f21a0a](https://github.com/Goopil/laravel-redis-sentinel/commit/7f21a0a5f9993156da4322d2c008602bbc0b6fa5))
* unquote horizon:pre-stop start-command default and return 1 on kill failure ([#76](https://github.com/Goopil/laravel-redis-sentinel/issues/76)) ([244bd6a](https://github.com/Goopil/laravel-redis-sentinel/commit/244bd6adc09512322070b7645f6c99185589515e))

## [1.5.1](https://github.com/Goopil/laravel-redis-sentinel/compare/1.5.0...1.5.1) (2026-09-02)


### Bug Fixes

* default node_cache.ttl to 15s so long-lived workers detect failovers ([#72](https://github.com/Goopil/laravel-redis-sentinel/issues/72)) ([4e4fd97](https://github.com/Goopil/laravel-redis-sentinel/commit/4e4fd97c43c720293b0a3bf5e847b9a509393c5c))
* default read_timeout to 60s to prevent indefinite hangs on half-open sockets ([#70](https://github.com/Goopil/laravel-redis-sentinel/issues/70)) ([bd225b2](https://github.com/Goopil/laravel-redis-sentinel/commit/bd225b272211b99d4b815f0988a5fb58cb8342f7))
* exclude replicas with a disconnected master link from read routing ([#71](https://github.com/Goopil/laravel-redis-sentinel/issues/71)) ([a1fe7ac](https://github.com/Goopil/laravel-redis-sentinel/commit/a1fe7ac33454ba7bf22b642b58cbcd60cabea506))

# [1.5.0](https://github.com/Goopil/laravel-redis-sentinel/compare/1.4.0...1.5.0) (2026-09-01)


### Bug Fixes

* add cancellation mechanism to blocking subscribe calls ([b45ce26](https://github.com/Goopil/laravel-redis-sentinel/commit/b45ce26b44835c5d80f80f0e0128cebd2db525ea))
* add iterable value type for readOnlyCommands (phpstan) ([6b4e538](https://github.com/Goopil/laravel-redis-sentinel/commit/6b4e5383490f7792e5d3cc74ad5c70796595da4c))
* add TTL to liveness check key to prevent Redis key leak ([8cd6fb6](https://github.com/Goopil/laravel-redis-sentinel/commit/8cd6fb6d0e8931caea6820b0823401e677abafce))
* add TTL to NodeAddressCache to prevent stale entries ([f1f2096](https://github.com/Goopil/laravel-redis-sentinel/commit/f1f20964c39ac31df661f5efc0fbab5e344f5633))
* address code review findings (crash, mutations, events, config injection) ([5d9e775](https://github.com/Goopil/laravel-redis-sentinel/commit/5d9e775a2c97bb35143cafc380e9764b7677d941))
* avoid thread-unsafe client mutation in Octane coroutine context ([55e21fd](https://github.com/Goopil/laravel-redis-sentinel/commit/55e21fd5cf758c575529328c73a7d8c422a33a89))
* correct cluster detection check in RedisSentinelManager ([0a540f7](https://github.com/Goopil/laravel-redis-sentinel/commit/0a540f78e1522d47c51d686e18ab8bedc95d2986))
* correct off-by-one error in retry attempt counting ([bae594a](https://github.com/Goopil/laravel-redis-sentinel/commit/bae594a7c65fe9e1f3618982953aac073a4fae1f))
* create RedisStore before passing it to repository in session handler ([55c5cf3](https://github.com/Goopil/laravel-redis-sentinel/commit/55c5cf3b8cf16f1fe8981bb84f42b0574dcf2fd8))
* define $resetCallback before using it in bootOctane foreach loop ([86ed0fa](https://github.com/Goopil/laravel-redis-sentinel/commit/86ed0faddb3dbd8e41388645e6147ac5b3a803f2))
* flush resolved redis instances on boot override ([1b84d46](https://github.com/Goopil/laravel-redis-sentinel/commit/1b84d46e87217b4f5aca7f9176c604965c65b31b))
* guard against unloaded phpredis extension in version detection ([3b34cc7](https://github.com/Goopil/laravel-redis-sentinel/commit/3b34cc780cda2c489de4e712e95bde95c54caa1a))
* handle associative array from phpredis 6.0+ in liveness probe ([99d3971](https://github.com/Goopil/laravel-redis-sentinel/commit/99d3971387656f12d2c61a1cedc9f22a90d2db45))
* handle multi-line pgrep output in preStop PID extraction ([268a3dd](https://github.com/Goopil/laravel-redis-sentinel/commit/268a3dd5319ff0f78f1ca49ec58378644496eb73))
* increase default sentinel connect timeout to 1.0s ([f7fc6e2](https://github.com/Goopil/laravel-redis-sentinel/commit/f7fc6e237939a158ab4a47fd2b37bc79746c2b3c))
* inject config instead of using global helper in connector ([eff89d1](https://github.com/Goopil/laravel-redis-sentinel/commit/eff89d144661a8564b18335dca307f597895766d))
* listen to all Octane events for stickiness reset ([99352f2](https://github.com/Goopil/laravel-redis-sentinel/commit/99352f2587441f288ef64043324ba00117daa67b))
* missing ) ([4652f85](https://github.com/Goopil/laravel-redis-sentinel/commit/4652f8552278319fd63d447a4cb5abf11132b78a))
* pass driver explicitly to connector to avoid singleton mutation ([9e09346](https://github.com/Goopil/laravel-redis-sentinel/commit/9e0934665bbb723c3fe6847317e8fa1e4f46fb33))
* pass normalized name to parent::resolve ([f8ee46d](https://github.com/Goopil/laravel-redis-sentinel/commit/f8ee46da327f7ae8255da221edcc68ae18597787))
* prevent cache store corruption from shallow clone ([19beb1a](https://github.com/Goopil/laravel-redis-sentinel/commit/19beb1acfed4deb54add1bb0abeace1e6baa217f))
* prevent retry on all exceptions when retryMessages is empty ([e42e49b](https://github.com/Goopil/laravel-redis-sentinel/commit/e42e49bc00c701e8122bf9e1f63e99ee516ac36d))
* prevent retry on application exceptions in pipeline/transaction ([88bb524](https://github.com/Goopil/laravel-redis-sentinel/commit/88bb524c8a390b469341ced69fb22f2da6174e2b))
* relax pgrep pattern matching in preStop command ([3cf760d](https://github.com/Goopil/laravel-redis-sentinel/commit/3cf760d3f2d421c853204d56655b5ca0c0fc91de))
* reset transaction level in resetStickiness to prevent master-only reads ([8c040e2](https://github.com/Goopil/laravel-redis-sentinel/commit/8c040e2ddeecfe1453561361890ed5f1df428614))
* resolve merge fallout with main (retry semantics, octane events, probe stubs) ([18c127f](https://github.com/Goopil/laravel-redis-sentinel/commit/18c127f3ff26dc12bd7a68f8a6902195717626db)), closes [#30](https://github.com/Goopil/laravel-redis-sentinel/issues/30)
* return masterClient explicitly in getReadClient fallback ([7a0f8b0](https://github.com/Goopil/laravel-redis-sentinel/commit/7a0f8b08c518f16f503572ced9b088460962fbb3))
* return non-zero exit code on preStop failure ([0067018](https://github.com/Goopil/laravel-redis-sentinel/commit/006701873caa00e5d25e3e4b0ba73ab30a7bbe05))
* run horizon-context manager tests only when Horizon is installed ([b8e31de](https://github.com/Goopil/laravel-redis-sentinel/commit/b8e31de0a9a8e41e85b5cc323e645f40d59de2c3))
* skip HorizonServiceBindingsTest when Horizon is not installed ([36cc521](https://github.com/Goopil/laravel-redis-sentinel/commit/36cc521239be4a6d05e8ca5048fca893ff4dfd60))
* use cryptographically safe random_int for replica selection ([4d6e812](https://github.com/Goopil/laravel-redis-sentinel/commit/4d6e8124732e2b8ae8af60bfc0739645abd84ecf))
* use str_starts_with to avoid false hostname matches in preStop ([fcbbff7](https://github.com/Goopil/laravel-redis-sentinel/commit/fcbbff710783799b80eff2dd234a5b49e257779b))
* use str_starts_with to avoid false hostname matches in readiness ([a0c85d4](https://github.com/Goopil/laravel-redis-sentinel/commit/a0c85d4df5a3e620482bc5a0a47ccb2df9696f2e))
* validate horizon.use config when in horizon context ([655dce9](https://github.com/Goopil/laravel-redis-sentinel/commit/655dce92048bc6cbc2b0895ad534c507a7925b89))


### Features

* make read-only command list configurable ([367350a](https://github.com/Goopil/laravel-redis-sentinel/commit/367350a376d34f32449aec08d2a5e58201a22f8a))

# [1.4.0](https://github.com/Goopil/laravel-redis-sentinel/compare/1.3.0...1.4.0) (2026-08-31)


### Features

* add valkey test bench for wire compatibility ([#44](https://github.com/Goopil/laravel-redis-sentinel/issues/44)) ([12225d8](https://github.com/Goopil/laravel-redis-sentinel/commit/12225d87d20527dab70a9992815628266f6bc6e4)), closes [#5](https://github.com/Goopil/laravel-redis-sentinel/issues/5) [#5](https://github.com/Goopil/laravel-redis-sentinel/issues/5) [#5](https://github.com/Goopil/laravel-redis-sentinel/issues/5) [#5](https://github.com/Goopil/laravel-redis-sentinel/issues/5) [#4](https://github.com/Goopil/laravel-redis-sentinel/issues/4)
* emit worker lifecycle events in Horizon probe commands ([#43](https://github.com/Goopil/laravel-redis-sentinel/issues/43)) ([bb48f21](https://github.com/Goopil/laravel-redis-sentinel/commit/bb48f21ca27c96d13276cd0dc4bde6bd22d72ffe)), closes [#4](https://github.com/Goopil/laravel-redis-sentinel/issues/4) [#4](https://github.com/Goopil/laravel-redis-sentinel/issues/4) [#4](https://github.com/Goopil/laravel-redis-sentinel/issues/4) [#4](https://github.com/Goopil/laravel-redis-sentinel/issues/4)

# [1.3.0](https://github.com/Goopil/laravel-redis-sentinel/compare/1.2.5...1.3.0) (2026-08-31)


### Features

* production-readiness remediation (single retry path, sentinel auth decoupling, node cache ttl, lazy boot) ([#35](https://github.com/Goopil/laravel-redis-sentinel/issues/35)) ([27211be](https://github.com/Goopil/laravel-redis-sentinel/commit/27211be1fccb615f05095f2bd1654f7c0d31ca50))

## [1.2.5](https://github.com/Goopil/laravel-redis-sentinel/compare/1.2.4...1.2.5) (2026-08-30)


### Bug Fixes

* patch dependency vulnerabilities and add blocking security audit gate ([#31](https://github.com/Goopil/laravel-redis-sentinel/issues/31)) ([c144f9b](https://github.com/Goopil/laravel-redis-sentinel/commit/c144f9bd7d4e2f148df6d327979b239a829ebdfd))

## [1.2.4](https://github.com/Goopil/laravel-redis-sentinel/compare/1.2.3...1.2.4) (2026-08-16)


### Bug Fixes

* remove llm folders ([d6c0872](https://github.com/Goopil/laravel-redis-sentinel/commit/d6c08723b8a75cf96e27aa30b9c213e3bbc751f6))
* sonarcloud code smells ([#30](https://github.com/Goopil/laravel-redis-sentinel/issues/30)) ([17bf23c](https://github.com/Goopil/laravel-redis-sentinel/commit/17bf23ca44bdc4289eeb9c06acb343e7268bb672))

## [1.2.3](https://github.com/Goopil/laravel-redis-sentinel/compare/1.2.2...1.2.3) (2026-05-22)


### Bug Fixes

* harden testing when Horizon is not installed ([#24](https://github.com/Goopil/laravel-redis-sentinel/issues/24)) ([eabbaed](https://github.com/Goopil/laravel-redis-sentinel/commit/eabbaed4be8c1d7682a50b327a9e487bb5ff082e))

## [1.2.2](https://github.com/Goopil/laravel-redis-sentinel/compare/1.2.1...1.2.2) (2026-05-21)


### Bug Fixes

* split NodeAddressCache cleanup methods for master & replicas ([#21](https://github.com/Goopil/laravel-redis-sentinel/issues/21)) ([498570f](https://github.com/Goopil/laravel-redis-sentinel/commit/498570f05d1a187666cfc9d6550f80a5abf83120))

## [1.2.1](https://github.com/Goopil/laravel-redis-sentinel/compare/1.2.0...1.2.1) (2026-05-21)


### Bug Fixes

* harden replicas random selection ([#22](https://github.com/Goopil/laravel-redis-sentinel/issues/22)) ([70abf74](https://github.com/Goopil/laravel-redis-sentinel/commit/70abf7466358566c68d380b91c9ff1d45c613c60))

# [1.2.0](https://github.com/Goopil/laravel-redis-sentinel/compare/1.1.0...1.2.0) (2026-05-21)


### Features

* allow disabling redis connection override ([#23](https://github.com/Goopil/laravel-redis-sentinel/issues/23)) ([5a4788c](https://github.com/Goopil/laravel-redis-sentinel/commit/5a4788c442755e36f19fa2f5a078caf11b54ee29))

# [1.1.0](https://github.com/Goopil/laravel-redis-sentinel/compare/1.0.1...1.1.0) (2026-02-21)


### Features

* php 8.5 support ([#9](https://github.com/Goopil/laravel-redis-sentinel/issues/9)) ([ad7f1c4](https://github.com/Goopil/laravel-redis-sentinel/commit/ad7f1c4f56d903082ba19912fe313bd36a7c2cd9))

## [1.0.1](https://github.com/Goopil/laravel-redis-sentinel/compare/v1.0.0...1.0.1) (2026-02-20)


### Bug Fixes

* partial npm audit fix ([c471389](https://github.com/Goopil/laravel-redis-sentinel/commit/c471389bb3dead25629dd1d2a110c9ca3a125c99))

# 1.0.0 (2026-01-12)


### Bug Fixes

* add missing single instance ([f94cfe3](https://github.com/Goopil/laravel-redis-sentinel/commit/f94cfe3356d65e352254004c3b56e5174053df2b))
* **ci:** improve e2e coverage & allow parallel testing on multiple redis, laravel & php versions ([c8353c4](https://github.com/Goopil/laravel-redis-sentinel/commit/c8353c479918cc568263e65ca94860297ab53c0f))
* code quality reports ([fb8a8cf](https://github.com/Goopil/laravel-redis-sentinel/commit/fb8a8cfb54c9bb85cdc92e51ae9e0b98a75edaed))
* handle specific log channel & add new message to retry ([aab4a9b](https://github.com/Goopil/laravel-redis-sentinel/commit/aab4a9be4802b1b4e40d3a6ee6035045e80528dc))
* missing password ([8b2e886](https://github.com/Goopil/laravel-redis-sentinel/commit/8b2e886ca1642ce22a412edad225077de9c0e788))
* Potential fix for code scanning alert no. 2: Workflow does not contain permissions ([#6](https://github.com/Goopil/laravel-redis-sentinel/issues/6)) ([a4bb880](https://github.com/Goopil/laravel-redis-sentinel/commit/a4bb880e40ae1cda555721b2e6a5e789e1bb7874))
* remove password on single instance ([8bf1df2](https://github.com/Goopil/laravel-redis-sentinel/commit/8bf1df2366a9e4fd721e449faace340751afd447))
* strict npm version & installation ([7fa3745](https://github.com/Goopil/laravel-redis-sentinel/commit/7fa37453eb105bcc2d1a1cdf0445b1528335ff34))
* typo in container name ([e71beb5](https://github.com/Goopil/laravel-redis-sentinel/commit/e71beb5457d96bcb965b68e37b7d77d4fa8ec0f6))
