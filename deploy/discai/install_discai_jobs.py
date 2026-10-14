from pathlib import Path
import subprocess
ops=Path('/srv/zyrex-ma/ops')
for name,script,schedule in [('provision','provision_discai_tenants.py','OnBootSec=2min\nOnUnitInactiveSec=2min'),('usage','discai_usage.py','OnBootSec=3min\nOnUnitInactiveSec=5min'),('backup','backup_discai.py','OnCalendar=daily\nPersistent=true')]:
 assert(ops/script).exists()
 service=Path('/etc/systemd/system')/('discai-'+name+'.service')
 service.write_text('[Unit]\nDescription=DiscAI '+name+'\nAfter=docker.service\n[Service]\nType=oneshot\nExecStart=/usr/bin/python3 '+str(ops/script)+'\nNice=10\nTimeoutStartSec=600\nUMask=0077\n[Install]\nWantedBy=multi-user.target\n');service.chmod(0o644)
 timer=service.with_suffix('.timer');timer.write_text('[Unit]\nDescription=DiscAI '+name+' schedule\n[Timer]\n'+schedule+'\nRandomizedDelaySec=15\nUnit=discai-'+name+'.service\n[Install]\nWantedBy=timers.target\n');timer.chmod(0o644)
subprocess.run(['systemctl','daemon-reload'],check=True)
for name in ['provision','usage','backup']:subprocess.run(['systemctl','enable','--now','discai-'+name+'.timer'],check=True)
hook=Path('/etc/letsencrypt/renewal-hooks/deploy/discai-reload.sh')
hook.parent.mkdir(parents=True,exist_ok=True)
hook.write_text('#!/bin/sh\ncase " $RENEWED_DOMAINS " in\n *"app.discai.ia.br"*) nginx -t && systemctl reload nginx ;;\nesac\n');hook.chmod(0o750)
print('Dedicated DiscAI timers enabled. Existing workloads were not restarted.')
