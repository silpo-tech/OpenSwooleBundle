# OpenSwoole Bundle for Symfony Framework #

[![CI](https://github.com/silpo-tech/OpenSwooleBundle/actions/workflows/ci.yml/badge.svg)](https://github.com/silpo-tech/OpenSwooleBundle/actions)
[![codecov](https://codecov.io/gh/silpo-tech/OpenSwooleBundle/graph/badge.svg)](https://codecov.io/gh/silpo-tech/OpenSwooleBundle)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)


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
    open_tcp_nodelay: false  # Disables Nagle's algorithm; set to true to reduce latency for small packets
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