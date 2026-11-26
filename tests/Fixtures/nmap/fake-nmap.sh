#!/bin/sh
# "nmap" falso para testes: emite XML em pedaços, com progresso entre hosts e um host
# partido no meio de "<hostnames>" (o leitor precisa esperar o </host>).
[ "$1" = "--version" ] && { echo "Nmap version 7.95 ( https://nmap.org )"; exit 0; }
printf '<?xml version="1.0"?>\n<nmaprun>\n<taskbegin task="ARP Ping Scan" time="1"/>\n'
printf '<taskprogress task="ARP Ping Scan" time="1" percent="10.00" remaining="9"/>\n'
printf '<host><status state="up" reason="arp-response"/>\n<address addr="192.168.10.20" addrtype="ipv4"/>\n<address addr="00:0C:29:AA:BB:01" addrtype="mac" vendor="VMware"/>\n<host'
sleep 0.3
printf 'names><hostname name="pc-01.empresa.local" type="PTR"/></hostnames>\n<ports><port protocol="tcp" portid="3389"><state state="open"/><service name="ms-wbt-server"/></port><port protocol="tcp" portid="445"><state state="open"/><service name="microsoft-ds"/></port></ports>\n'
printf '<hostscript><script id="nbstat" output="NetBIOS name: PC-01, NetBIOS user: &lt;unknown&gt;, NetBIOS MAC: 00:0c:29:aa:bb:01 (VMware)"/></hostscript></host>\n'
printf '<taskprogress task="SYN Stealth Scan" time="2" percent="55.50" remaining="3"/>\n'
printf '<host><status state="up" reason="echo-reply"/><address addr="192.168.10.30" addrtype="ipv4"/><ports><port protocol="tcp" portid="9100"><state state="open"/><service name="jetdirect"/></port></ports></host>\n'
printf '<host><status state="down" reason="no-response"/><address addr="192.168.10.31" addrtype="ipv4"/></host>\n'
printf '<runstats><finished exit="success"/></runstats>\n</nmaprun>\n'
