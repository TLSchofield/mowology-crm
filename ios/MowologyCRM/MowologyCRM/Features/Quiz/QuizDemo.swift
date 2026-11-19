//
//  QuizDemo.swift — DEBUG only: canned pre-shift quiz questions for design checks (`-mwQuizDemo`). Not in Release.
//
#if DEBUG
import Foundation
import UIKit

struct QuizDemoQuestion {
    let category: String
    let text: String
    let options: [String]
    let correct: Int          // index of the right option
    let photo: String?        // "long" | "wide" | nil

    static var script: [QuizDemoQuestion] {
        [
            QuizDemoQuestion(category: "Turf disease",
                             text: "In late summer, yellow-orange powder rubs off ryegrass onto your boots. Which disease is it?",
                             options: ["Rust", "Dollar Spot", "Helminthosporium Leaf Spot", "Brown Patch"], correct: 0, photo: "long"),
            QuizDemoQuestion(category: "Safety",
                             text: "A string trimmer throws debris at 60 m/s. Which protection do you need before you start it?",
                             options: ["Safety glasses and hearing protection", "Gloves only", "A hard hat", "Nothing if the area is empty"], correct: 0, photo: nil),
            QuizDemoQuestion(category: "Plant ID", text: "Which plant is this?",
                             options: ["Japanese Maple", "Red Oak", "Sweetgum", "Sugar Maple"], correct: 2, photo: "wide"),
        ]
    }

    func response(num: Int, total: Int) -> QuizQuestionResponse {
        var image = ""
        if let photo {
            let path = (NSTemporaryDirectory() as NSString).appendingPathComponent("quizdemo-\(photo).png")
            if !FileManager.default.fileExists(atPath: path) {
                let size = photo == "long" ? CGSize(width: 600, height: 1000) : CGSize(width: 1000, height: 640)
                let png = UIGraphicsImageRenderer(size: size).pngData { c in
                    UIColor(red: 0.45, green: 0.62, blue: 0.35, alpha: 1).setFill(); c.fill(CGRect(origin: .zero, size: size))
                    UIColor(red: 0.85, green: 0.55, blue: 0.1, alpha: 1).setFill()
                    c.cgContext.fillEllipse(in: CGRect(x: size.width * 0.3, y: size.height * 0.55, width: size.width * 0.25, height: size.width * 0.25))
                    UIColor.white.setStroke(); c.cgContext.setLineWidth(8)
                    c.cgContext.stroke(CGRect(origin: .zero, size: size).insetBy(dx: 4, dy: 4))
                }
                try? png.write(to: URL(fileURLWithPath: path))
            }
            image = "file://" + path
        }
        let opts = options.enumerated().map { ["id": num * 10 + $0.offset, "option_text": $0.element] }
        let json: [String: Any] = [
            "success": true, "question_num": num, "total": total,
            "question": ["id": 1000 + num, "text": text, "category_name": category, "mastery_level": 1, "mastery_name": "Learning",
                         "images": image.isEmpty ? [] : [["image_path": image, "caption": "Seen at Oakridge · Front bed"]]],
            "options": opts,
        ]
        return try! JSONDecoder().decode(QuizQuestionResponse.self, from: JSONSerialization.data(withJSONObject: json))
    }
}
#endif
