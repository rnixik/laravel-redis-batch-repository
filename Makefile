test:
	docker network create laravel-redis-batch-repository_network || true
	docker rm --force laravel-redis-batch-repository_app || true
	docker rm --force laravel-redis-batch-repository_redis || true
	docker run -d --network laravel-redis-batch-repository_network --name laravel-redis-batch-repository_redis redis:6
	docker build -t laravel-redis-batch-repository_app -f ./Dockerfile .
	docker run --rm -it --network laravel-redis-batch-repository_network \
       --name laravel-redis-batch-repository_app \
       -v "$(shell pwd):$(shell pwd)" -w "$(shell pwd)"  laravel-redis-batch-repository_app \
       bash -c "composer install; REDIS_HOST=laravel-redis-batch-repository_redis vendor/bin/phpunit tests"
	docker stop laravel-redis-batch-repository_redis || true
	docker network rm laravel-redis-batch-repository_network || true
