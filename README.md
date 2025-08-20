Installation
============

Open a command console, enter your project directory and execute:

```console
$ composer require silpo-tech/openswoole-bundle
```

USAGE
----------------------------------

```bash
# Start the openswoole server
$ php bin/console openswoole:server:start
```

```bash
# Stop the openswoole server
$ php bin/console openswoole:server:stop
```

```bash
# Reload the openswoole server
$ php bin/console openswoole:server:reload
```

Configuration
----------------------------------

### Default Configs
```yaml
host: 0.0.0.0
port: 80
options:
    pid_file: /var/run/openswoole_server.pid
    log_file: %kernel.logs_dir%/swoole.log
    daemonize: false
    max_requests: 200
    worker_num: 4
    document_root: %kernel.project_dir%/public
    enable_static_handler: false
    open_http2_protocol: false
```

### Other Configs
*Note: these options have not been tried*

```yaml
options:
    max_request: ~
    open_cpu_affinity: ~
    task_worker_num: ~
    enable_port_reuse: ~
    worker_num: ~
    reactor_num: ~
    dispatch_mode: ~
    discard_timeout_request: ~
    open_tcp_nodelay: ~
    open_mqtt_protocol: ~
    user: ~
    group: ~
    ssl_cert_file: ~
    ssl_key_file: ~
    package_max_length: ~
```
### Tests
To run the test suite, you need to install the dependencies and run the test suite:

```bash
composer install --ignore-platform-reqs

docker pull fozzyua/docker-php-openswoole-base-image:latest
```
Run the test suite:

```bash
docker run --rm -v ./:/var/www/project/ --workdir /var/www/project/ --entrypoint composer  fozzyua/docker-php-openswoole-base-image:latest test:run
```