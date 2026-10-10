# BINARY FILE
PROJECT="redi-multi"
# START FILE PATH
MAIN_PATH="main.go"
build:
	@go build -ldflags "-linkmode external -extldflags '-static'" -trimpath -o bin/${PROJECT} ${MAIN_PATH}