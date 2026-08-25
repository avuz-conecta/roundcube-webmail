import socket, time
HOST,PORT='127.0.0.1',1143
USER,PASS='spike@example.com','spikepass'
def conn():
    s=socket.create_connection((HOST,PORT),timeout=10); s.recv(4096); return s
def snd(s,d): s.sendall(d.encode())
def rcv(s,n=16384):
    time.sleep(0.2)
    try: return s.recv(n).decode(errors='replace')
    except: return ''

c=conn()
snd(c,f'l1 LOGIN {USER} {PASS}\r\n'); rcv(c)

# --- Deliberately DESYNC: pipeline a batch, then read fewer replies than sent ---
# send SELECT+SEARCH for 4 folders (8 tagged commands) all at once
snd(c,''.join(f's{i} SELECT F{i:03d}\r\nq{i} UID SEARCH HEADER SUBJECT needle-hit\r\n' for i in range(1,5)))
time.sleep(0.4)
partial=rcv(c)   # read only what's arrived so far, intentionally leaving replies buffered
print(f'after partial read, buffered replies remain. last line seen: {partial.strip().splitlines()[-1] if partial.strip() else "<none>"}')

# --- RESYNC: send a uniquely-tagged NOOP, read-and-discard until that tag appears ---
RESYNC_TAG='ZZ99'
snd(c,f'{RESYNC_TAG} NOOP\r\n')
buf=''; aligned=False; t0=time.time(); budget=3.0; capbytes=65536
while time.time()-t0 < budget and len(buf) < capbytes:
    chunk=rcv(c)
    if not chunk: break
    buf+=chunk
    # aligned when we see our own tag's completion line
    for ln in buf.splitlines():
        if ln.startswith(RESYNC_TAG+' '):
            aligned=True; break
    if aligned: break
print(f'RESYNC drained {len(buf)} bytes, aligned_to_{RESYNC_TAG}={aligned}')

# --- prove alignment: a fresh command now gets ITS OWN reply ---
snd(c,'AA11 NOOP\r\n'); r=rcv(c)
clean = any(ln.startswith('AA11 ') for ln in r.splitlines())
print(f'post-resync fresh NOOP got own tag AA11: {clean}')
print(f'\n>>> RESYNC MECHANISM WORKS: {aligned and clean}')
# then LOGOUT returns a clean connection to the pool
snd(c,'QQ LOGOUT\r\n'); rcv(c); c.close()
