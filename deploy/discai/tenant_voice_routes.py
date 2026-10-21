"""Tenant WebSocket route with same-origin and authenticated session checks."""
import re

def install_tenant_voice_routes(config, host, port):
    if not re.fullmatch(r'[a-z0-9-]+(?:\.app)?\.discai\.ia\.br', host) or not 19101 <= int(port) <= 19600:
        raise ValueError('Invalid tenant voice target')
    marker=f'location / {{ proxy_pass http://127.0.0.1:{port};'
    if config.count(marker)!=1 or '/voice/ws' in config:
        raise ValueError('Expected unmodified tenant HTTPS upstream')
    index=config.index(marker)
    servers=list(re.finditer(r'\bserver\s*\{',config[:index]))
    if not servers or not re.search(r'\blisten\s+443\s+ssl\b',config[servers[-1].start():index]):
        raise ValueError('Tenant upstream must use HTTPS')
    locations='''location = /_discai_tenant_voice_auth {
 internal;
 proxy_pass http://127.0.0.1:PORT/api/voice/audio/authorize;
 proxy_pass_request_body off;
 proxy_set_header Content-Length "";
 proxy_set_header Host $host;
 proxy_set_header X-Forwarded-Proto https;
 proxy_set_header Cookie $http_cookie;
 proxy_set_header Accept application/json;
}
location = /voice/ws {
 if ($http_origin != "https://TENANTHOST") { return 403; }
 auth_request /_discai_tenant_voice_auth;
 proxy_pass http://127.0.0.1:18488/ws;
 proxy_http_version 1.1;
 proxy_set_header Upgrade $http_upgrade;
 proxy_set_header Connection "upgrade";
 proxy_set_header Host $host;
 proxy_read_timeout 300s;
 proxy_send_timeout 90s;
 proxy_buffering off;
}
'''.replace('PORT',str(port)).replace('TENANTHOST',host)
    return config[:index]+locations+config[index:]
