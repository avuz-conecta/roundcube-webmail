"""TCP forwarder that delays every byte by DELAY_MS in each direction (simulates RTT)."""
import socket, threading, time, sys
LISTEN = int(sys.argv[1]); TARGET = int(sys.argv[2]); DELAY = float(sys.argv[3]) / 1000.0

def pump(src, dst):
    try:
        while True:
            d = src.recv(65536)
            if not d: break
            time.sleep(DELAY)
            dst.sendall(d)
    except OSError:
        pass
    finally:
        try: dst.shutdown(socket.SHUT_WR)
        except OSError: pass

def handle(c):
    u = socket.create_connection(("127.0.0.1", TARGET))
    threading.Thread(target=pump, args=(c, u), daemon=True).start()
    pump(u, c)
    c.close(); u.close()

srv = socket.socket()
srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(("127.0.0.1", LISTEN)); srv.listen(16)
print(f"delay proxy :{LISTEN} -> :{TARGET} ({DELAY*1000:.0f}ms each way)", flush=True)
while True:
    c, _ = srv.accept()
    threading.Thread(target=handle, args=(c,), daemon=True).start()
