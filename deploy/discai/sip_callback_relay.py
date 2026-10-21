"""Private, tenant-specific PBX callbacks; never expose provider credentials."""
import ipaddress
import re
from urllib.parse import urlsplit


def relay_network(pbx):
    network = pbx['NetworkSettings']['Networks']['zyrex-ma_default']
    gateway = ipaddress.IPv4Address(network['Gateway'])
    address = ipaddress.IPv4Address(network['IPAddress'])
    if not gateway.is_private or not address.is_private or gateway == address:
        raise RuntimeError('Invalid private PBX network')
    return str(gateway), str(address)


def relay_target(app_name, callback, web):
    if not re.fullmatch(r'(zyrex-ma|discai-tenant-[a-z0-9-]+)-app-1', app_name):
        raise RuntimeError('Invalid tenant application')
    expected = app_name.removesuffix('-app-1') + '-web-1'
    if web['Name'].removeprefix('/') != expected:
        raise RuntimeError('Callback web container does not match tenant')
    bindings = web['NetworkSettings']['Ports'].get('80/tcp') or []
    if len(bindings) != 1 or bindings[0]['HostIp'] != '127.0.0.1':
        raise RuntimeError('Callback requires an isolated loopback web port')
    port = bindings[0]['HostPort']
    if not port.isdigit() or not 1024 <= int(port) <= 65535:
        raise RuntimeError('Invalid callback web port')
    if not re.fullmatch(r'https://[a-z0-9.-]+/internal/voice/calling/event', callback):
        raise RuntimeError('Invalid tenant callback')
    return {'port': int(port), 'host': urlsplit(callback).hostname}


def relay_config(gateway, pbx_address, targets):
    for address in [gateway, pbx_address]:
        if not ipaddress.IPv4Address(address).is_private:
            raise RuntimeError('Callback relay must stay private')
    lines = [
        '# Managed DiscAI PBX callbacks. No public listener or unauthenticated action.',
        'server {', f' listen {gateway}:18489;', ' server_name _;',
        f' allow {pbx_address};', ' deny all;', ' access_log off;',
        ' client_max_body_size 4k;', ' client_body_timeout 5s;',
        ' location / { return 404; }',
    ]
    for identity, target in sorted(targets.items()):
        if not re.fullmatch(r'discai-browser-[a-f0-9]{16}', identity):
            raise RuntimeError('Invalid callback identity')
        if not re.fullmatch(r'[a-z0-9.-]+', target['host']) or not 1024 <= target['port'] <= 65535:
            raise RuntimeError('Invalid callback target')
        lines.extend([
            f' location = /{identity} {{', '  limit_except POST { deny all; }',
            f"  proxy_pass http://127.0.0.1:{target['port']}/internal/voice/calling/event;",
            f"  proxy_set_header Host {target['host']};",
            '  proxy_set_header Cookie "";', '  proxy_set_header X-Forwarded-Proto https;',
            '  proxy_connect_timeout 2s;', '  proxy_read_timeout 5s;',
            '  proxy_next_upstream off;', ' }',
        ])
    return '\n'.join(lines + ['}', ''])
