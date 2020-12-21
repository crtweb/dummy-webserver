Dummy http-server for testing purposes
======================================

You can test parts of your application which calls the remote APIs with this. This service gets your http-requests and tries to read files from defined directory. The name of file (content of this file will be response) must be the same as http-request path, but every slash must will be translated as dash.

If file with this name exists, it contents will be the body of response.

## How to run

You can run it directly as console command. Clone the repository and type this

```shell script
bin/console app:web-server -vv
```

This will run server with default parameters.

Also you can run it as docker-container

```shell script
docker run -it --rm git.crtweb.ru:4567/creative-packages/dummy-http-server/server:latest
```

or build it by yourself

```shell script
docker build -t server:latest -f docker/Dockerfile .
```

## Parameters

```
Arguments:
  data-dir              Directory with files for responses [default: "responses"]

Options:
  -p, --port[=PORT]     Port for listening [default: 8080]
      --host[=HOST]     Interface for listening [default: "0.0.0.0"]
  -h, --help            Display this help message
  -q, --quiet           Do not output any message
  -v|vv|vvv, --verbose  Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug
```

## Default properties

- All responses will contains `Content-type: application/json` header, if you are not provide the `Accept` header with another type. If you are, response header `Content-type` will be the same as request header `Accept`.
- If request path not have a extension (`path/to/something`, not `path/to/something.json`) the server will search file with `.json` extension.

## For example

Remote API have to respond with JSON like

```json
[
    {
      "city": "New York",
      "state": "New York",
      "street": "Manhattan",
      "is_confirmed": true
    }
]
```

for request `/api/get-confirmed-places`.

Place this json to file `responses/api-get-confirmed-places.json` (`json` is default extension for file when request has not an extension), run the server and send curl request to it:

```shell script
curl -X POST -D - -H "Origin: https://my-host.org" -H "Content-Type: application/json" \
  http://localhost:8080/api/get-confirmed-places -d '{"some-data":"foo-bar"}'
```

As you can see, the body of response is the same as contents of file.

If file with target name not will be found, you will receive the 404-response.

## Future plans

- [ ] Add possibility to throw exceptions from responses;
- [ ] Add possibility to receive special response headers;
- [ ] Add possibility to get streamed responses;
