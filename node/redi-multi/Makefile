# BINARY FILE
PROJECT="redi-multi"
# START FILE PATH
MAIN_PATH="main.go"
build:
	@CGO_ENABLED=0 go build -ldflags "-s -w" -trimpath -o bin/${PROJECT} ${MAIN_PATH}