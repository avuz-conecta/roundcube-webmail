import sys, socket, time; sys.path.insert(0,'.')
HOST,PORT='127.0.0.1',1143
USER,PASS='spike@example.com','spikepass'

def conn():
    s=socket.create_connection((HOST,PORT),timeout=10); s.recv(4096); return s
def sendall(s,d): s.sendall(d.encode())
def recv(s,n=8192):
    try: return s.recv(n).decode(errors='replace')
    except Exception as e: return f'<recv err {e}>'

# --- Client 1: login, pipeline a batch, read ONLY the first reply, close abruptly ---
c1=conn()
sendall(c1,f'l1 LOGIN {USER} {PASS}\r\n'); login1=recv(c1)
reused1='XPROXYREUSE' in login1
# pipeline SELECT+SEARCH for several folders, all at once
batch=''.join(f's{i} SELECT F{i:03d}\r\nq{i} UID SEARCH HEADER SUBJECT needle-hit\r\n' for i in range(1,6))
sendall(c1,batch)
time.sleep(0.3)
first=recv(c1)               # read only the FIRST chunk -> leaves many replies buffered upstream
print(f'C1 login reused={reused1}  first_reply_head={first.strip().splitlines()[0] if first.strip() else "<none>"}')
c1.close()                   # ABRUPT close, no LOGOUT  <-- the closeSocket() case
time.sleep(0.5)              # give imapproxy time to pool (or discard) the upstream conn

# --- Client 2: login (should REUSE the pooled upstream), send clean NOOP, check tag ---
c2=conn()
sendall(c2,f'm1 LOGIN {USER} {PASS}\r\n'); login2=recv(c2)
reused2='XPROXYREUSE' in login2
sendall(c2,'z9 NOOP\r\n'); time.sleep(0.3); reply=recv(c2)
print(f'C2 login reused={reused2}')
print(f'C2 NOOP (tag should be z9):')
for ln in reply.strip().splitlines(): print('   ', ln)
poison = 'z9 ' not in reply    # if the tagged completion isn't z9, the stream is poisoned
print(f'\n>>> D1 POISON REPRODUCED: {poison}   (C2 got replies not belonging to its NOOP)')
c2.close()
