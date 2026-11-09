import Foundation

var failures = 0
func check(_ got: String?, _ want: String?, _ label: String) {
    if got != want { failures += 1; print("FAIL \(label): got \(got ?? "nil"), want \(want ?? "nil")") }
}

let host = Server.base.absoluteString
check(String(host.last!) == "/" ? "trailing slash" : nil, nil, "base has no trailing slash")
check(Server.url("/a")?.absoluteString, host + "/a", "rooted path")
check(Server.url("a")?.absoluteString, host + "/a", "path without leading slash")
check(Server.url("https://x.example/y")?.absoluteString, "https://x.example/y", "absolute URL passes through")
check(Server.url("http://x.example/y")?.absoluteString, "http://x.example/y", "http URL passes through")
check(Server.api.absoluteString, host + "/api", "api is base + /api")
check(Server.url("/crm/img/heads/otto.jpg")?.absoluteString, "https://mowology.ca/crm/img/heads/otto.jpg", "today's address unchanged")

if failures > 0 { print("Server: \(failures) failed"); exit(1) }
print("Server: all passed")
