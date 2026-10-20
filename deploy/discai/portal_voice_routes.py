"""Install voice locations in the HTTPS portal, never its HTTP redirect server."""
import re


def install_voice_routes(config: str, locations: str) -> str:
    marker = 'location / { proxy_pass http://127.0.0.1:19090;'
    if config.count(marker) != 1 or '/platform-calling/voice/ws' in config:
        raise ValueError('Expected one unmodified portal upstream')
    index = config.index(marker)
    servers = list(re.finditer(r'\bserver\s*\{', config[:index]))
    if not servers or not re.search(r'\blisten\s+443\s+ssl\b', config[servers[-1].start():index]):
        raise ValueError('Portal upstream must belong to the HTTPS server')
    return config[:index] + locations + '\n' + config[index:]
