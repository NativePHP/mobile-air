import Foundation
import Testing
@testable import NativePHP

struct EventFunctionsTests {

    /// Stands in for the element event queue. It keeps every post and
    /// answers the way the queue does: true is queued, false dropped.
    final class PostRecorder {
        var queues = true
        private(set) var posts: [(event: String, payloadJson: String)] = []

        func record(_ event: String, _ payloadJson: String) -> Bool {
            posts.append((event: event, payloadJson: payloadJson))

            return queues
        }
    }

    let recorder = PostRecorder()

    func broadcast(queues: Bool) -> EventFunctions.Broadcast {
        recorder.queues = queues

        return EventFunctions.Broadcast(post: recorder.record)
    }

    /// Decode a JSON object the way the bridge router decodes parameters.
    func decode(_ json: String) throws -> [String: Any] {
        let object = try JSONSerialization.jsonObject(with: Data(json.utf8))

        return try #require(object as? [String: Any])
    }

    /// The frame PHP sends: the serialized event as base64 and its hex
    /// signature. The `/` is in there as JSON escapes it on the way.
    let eventData = "TzoyMzoiQXBw+EV2ZW50c1xP/mRlclNoaXBwZWQiOjA6e30="
    let signature = "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08"

    @Test func frameIsPostedOnceUnderGlobalEventNameWithPayloadUnchanged() throws {
        let payload: [String: Any] = ["data": eventData, "sig": signature]

        let reply = try broadcast(queues: true).execute(parameters: ["payload": payload])

        #expect(reply["success"] as? Bool == true)
        #expect(reply["delivered"] as? Bool == true)
        #expect(reply.count == 2)

        try #require(recorder.posts.count == 1)
        #expect(recorder.posts[0].event == "__global_event")

        let posted = try decode(recorder.posts[0].payloadJson)
        #expect(posted["data"] as? String == eventData)
        #expect(posted["sig"] as? String == signature)
        #expect(posted.count == 2)
    }

    @Test func droppedPostAnswersNotDelivered() throws {
        let payload: [String: Any] = ["data": eventData, "sig": signature]

        let reply = try broadcast(queues: false).execute(parameters: ["payload": payload])

        #expect(reply["success"] as? Bool == true)
        #expect(reply["delivered"] as? Bool == false)
        #expect(reply.count == 2)

        try #require(recorder.posts.count == 1)
        #expect(recorder.posts[0].event == "__global_event")

        let posted = try decode(recorder.posts[0].payloadJson)
        #expect(posted["data"] as? String == eventData)
        #expect(posted["sig"] as? String == signature)
        #expect(posted.count == 2)
    }

    @Test func eventParameterIsIgnored() throws {
        let payload: [String: Any] = ["data": eventData, "sig": signature]
        let function = broadcast(queues: true)

        _ = try function.execute(parameters: [
            "event": "__deeplink",
            "payload": payload
        ])

        _ = try function.execute(parameters: [
            "event": "App\\Events\\OrderShipped",
            "payload": payload
        ])

        try #require(recorder.posts.count == 2)
        #expect(recorder.posts[0].event == "__global_event")
        #expect(recorder.posts[1].event == "__global_event")
    }

    @Test func missingPayloadAnswersErrorAndPostsNothing() throws {
        let reply = try broadcast(queues: true).execute(parameters: [:])

        #expect(reply["success"] as? Bool == false)
        #expect(reply["error"] as? String == "missing payload")
        #expect(reply.count == 2)
        #expect(recorder.posts.isEmpty)
    }

    @Test func payloadThatIsNotAnObjectAnswersErrorAndPostsNothing() throws {
        let list: [Any] = [eventData, signature]
        let function = broadcast(queues: true)

        let forString = try function.execute(parameters: ["payload": eventData])
        let forArray = try function.execute(parameters: ["payload": list])

        for reply in [forString, forArray] {
            #expect(reply["success"] as? Bool == false)
            #expect(reply["error"] as? String == "missing payload")
            #expect(reply.count == 2)
        }

        #expect(recorder.posts.isEmpty)
    }

    @Test func emptyObjectPayloadIsPosted() throws {
        let payload: [String: Any] = [:]

        let reply = try broadcast(queues: true).execute(parameters: ["payload": payload])

        #expect(reply["success"] as? Bool == true)
        #expect(reply["delivered"] as? Bool == true)
        #expect(reply.count == 2)

        try #require(recorder.posts.count == 1)
        #expect(recorder.posts[0].event == "__global_event")
        #expect(recorder.posts[0].payloadJson == "{}")
    }

    @Test func nestedPayloadValuesSurviveTheJsonRoundTrip() throws {
        let parameters = try decode(#"""
        {
            "payload": {
                "lines": ["shirt", 2, true],
                "customer": {"name": "Ada", "address": {"city": "Utrecht"}},
                "total": 12.5,
                "quantity": 3,
                "paid": true,
                "gift": false,
                "note": null
            }
        }
        """#)

        _ = try broadcast(queues: true).execute(parameters: parameters)

        try #require(recorder.posts.count == 1)

        let json = recorder.posts[0].payloadJson
        let posted = try decode(json)

        let lines = try #require(posted["lines"] as? [Any])
        #expect(lines.count == 3)
        #expect(lines[0] as? String == "shirt")
        #expect(lines[1] as? Int == 2)
        #expect(lines[2] as? Bool == true)

        let customer = try #require(posted["customer"] as? [String: Any])
        let address = try #require(customer["address"] as? [String: Any])
        #expect(customer["name"] as? String == "Ada")
        #expect(address["city"] as? String == "Utrecht")

        #expect(posted["total"] as? Double == 12.5)
        #expect(posted["quantity"] as? Int == 3)
        #expect(posted["paid"] as? Bool == true)
        #expect(posted["gift"] as? Bool == false)
        #expect(posted["note"] is NSNull)
        #expect(posted.count == 7)

        // Once decoded, `true` and the number 1 both cast to a Bool, so
        // the JSON text has to show that the bools are still bools.
        #expect(json.contains("\"paid\":true"))
        #expect(json.contains("\"gift\":false"))
    }

    @Test func answerFollowsThePostResultOnEveryCall() throws {
        let payload: [String: Any] = ["data": eventData, "sig": signature]
        let function = broadcast(queues: false)
        let parameters: [String: Any] = ["payload": payload]

        let first = try function.execute(parameters: parameters)

        recorder.queues = true
        let second = try function.execute(parameters: parameters)

        recorder.queues = false
        let third = try function.execute(parameters: parameters)

        #expect(first["delivered"] as? Bool == false)
        #expect(second["delivered"] as? Bool == true)
        #expect(third["delivered"] as? Bool == false)
        #expect(recorder.posts.count == 3)
    }

}
