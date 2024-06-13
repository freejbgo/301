import socketserver
import socket
import ssl

class TCPHandler(socketserver.BaseRequestHandler):
    def handle(self):
        context = ssl.SSLContext(ssl.PROTOCOL_SSLv23)
        context.load_cert_chain(certfile='/root/JumpPython/ssl.pem') # SSL证书
        SSLSocket = context.wrap_socket(self.request, server_side=True)
        while True:
            try:
                self.data = SSLSocket.recv(1024).strip().decode('utf-8')
                host = self.data.split('\r\n')[1].split(':')[1].strip()
                domain = host.replace("https://", "").replace("http://", "").replace("www.", "").split("/")[0]
                url = "你的跳转域名，u作为参数用于区分域名?u={}".format(domain)
                data = {
                    "Http_Status": "HTTP/1.1 302 Moved Temporarily",
                    "Content-Type": "text/html; charset=utf-8",
                    "Connection": "close",
                    "Location": url}

                self.data = bytes("{0}\r\nContent-Type: {1}\r\nscheme: {2}\r\nLocation: {3}\r\n\r\n".format( \
                    data['Http_Status'], data['Content-Type'], data['Connection'], data['Location']), 'ascii')

                self.server.request_queue_size = 1024
                self.server.socket.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
                self.server.socket.setsockopt(socket.IPPROTO_TCP, socket.TCP_NODELAY, 1)

                SSLSocket.sendall(self.data)
                break

            except ConnectionResetError as e:
                print(self.client_address, e)
                break

if __name__ == '__main__':
    HOST, PORT = '0.0.0.0', 443
    with socketserver.ThreadingTCPServer((HOST, PORT), TCPHandler) as server:
        server.serve_forever()