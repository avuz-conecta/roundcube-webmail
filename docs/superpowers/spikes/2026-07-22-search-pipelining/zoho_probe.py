import socket, ssl, time
ctx = ssl.create_default_context()
s = ctx.wrap_socket(socket.create_connection(("imap.zoho.com", 993), timeout=15),
                    server_hostname="imap.zoho.com")
def rd(n=8192):
    s.settimeout(6)
    out=b""
    try:
        while True:
            d=s.recv(n)
            if not d: break
            out+=d
            if b"z3 " in out: break
    except socket.timeout:
        pass
    return out
greet=s.recv(4096)
print("GREET:", greet.decode(errors="replace").strip())
t0=time.time()
s.sendall(b"z1 CAPABILITY\r\nz2 NOOP\r\nz3 CAPABILITY\r\n")
data=rd()
dt=time.time()-t0
print("ELAPSED %.3fs" % dt)
print(data.decode(errors="replace"))
s.close()
