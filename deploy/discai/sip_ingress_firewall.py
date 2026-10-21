#!/usr/bin/python3
"""Restrict Docker-published carrier SIP ports without changing unrelated rules."""
import ipaddress,socket,subprocess
from sip_inbound_runtime import TWILIO_NETWORKS
CHAIN='DISCAI-SIP-IN'
def run(args,check=True):return subprocess.run(['iptables','-w','5',*args],capture_output=True,text=True,check=check)
def main():
 addresses=sorted({a[4][0] for a in socket.getaddrinfo('vono2.me',5060,socket.AF_INET,socket.SOCK_DGRAM)})
 for ip in addresses:ipaddress.IPv4Address(ip)
 # A dedicated, idempotent chain only for SIP to the DiscAI PBX.
 run(['-N',CHAIN],False)
 def add(rule):
  if run(['-C',CHAIN,*rule],False).returncode:run(['-I',CHAIN,'1',*rule])
 add(['-p','tcp','-d','10.241.50.10','--dport','15062','-j','DROP'])
 add(['-p','udp','-d','10.241.50.10','--dport','15060','-j','DROP'])
 for network in TWILIO_NETWORKS:add(['-p','tcp','-s',network,'-d','10.241.50.10','--dport','15062','-j','RETURN'])
 for ip in addresses:add(['-p','udp','-s',ip+'/32','-d','10.241.50.10','--dport','15060','-j','RETURN'])
 add(['-m','conntrack','--ctstate','ESTABLISHED,RELATED','-j','RETURN'])
 # No IPv6 address is published for these ports.
 if run(['-C','DOCKER-USER','-j',CHAIN],False).returncode:run(['-I','DOCKER-USER','1','-j',CHAIN])
 print('DiscAI carrier SIP firewall ready')
if __name__=='__main__':main()
