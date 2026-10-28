import Foundation
import UIKit
import Vision

/// Reads the receipt's text on the phone (Apple Vision) so the server can compare it with, and later
/// stand in for, its own Google Vision read. Best effort: any failure returns nil and the upload goes
/// ahead exactly as before. See app/Services/Receipts/DeviceOcrCompare.php for what the server does with it.
enum DeviceTextScanner {
    struct Result {
        let text: String
        let engine = "apple_vision"
        let version: String
    }

    /// Longest we let the on-device read hold the upload.
    private static let timeoutSeconds: Double = 6

    static func read(_ imageData: Data) async -> Result? {
        guard let cgImage = UIImage(data: imageData)?.cgImage else { return nil }
        return await withTaskGroup(of: Result?.self) { group in
            group.addTask { await recognise(cgImage) }
            group.addTask {
                try? await Task.sleep(nanoseconds: UInt64(timeoutSeconds * 1_000_000_000))
                return nil
            }
            let first = await group.next() ?? nil
            group.cancelAll()
            return first
        }
    }

    private static func recognise(_ cgImage: CGImage) async -> Result? {
        await withCheckedContinuation { continuation in
            DispatchQueue.global(qos: .userInitiated).async {
                let request = VNRecognizeTextRequest()
                request.recognitionLevel = .accurate
                request.usesLanguageCorrection = false   // prices and codes, not prose
                let handler = VNImageRequestHandler(cgImage: cgImage, options: [:])
                do {
                    try handler.perform([request])
                } catch {
                    continuation.resume(returning: nil)
                    return
                }
                let observations = (request.results ?? [])
                // Top of the receipt first; Vision's y runs bottom-up. Lines at (nearly) the same height
                // stay on one line, left to right, so "TOTAL   23.28" is not split across lines.
                let rows = Self.rows(from: observations)
                let text = rows.joined(separator: "\n")
                guard !text.isEmpty else { continuation.resume(returning: nil); return }
                continuation.resume(returning: Result(text: text, version: "r\(request.revision)"))
            }
        }
    }

    private static func rows(from observations: [VNRecognizedTextObservation]) -> [String] {
        struct Piece { let y: CGFloat; let x: CGFloat; let h: CGFloat; let text: String }
        let pieces: [Piece] = observations.compactMap { o in
            guard let top = o.topCandidates(1).first else { return nil }
            return Piece(y: o.boundingBox.midY, x: o.boundingBox.minX, h: o.boundingBox.height, text: top.string)
        }.sorted { $0.y > $1.y }

        var rows: [[Piece]] = []
        for p in pieces {
            if var last = rows.last, let ref = last.first, abs(ref.y - p.y) < max(ref.h, p.h) * 0.6 {
                last.append(p)
                rows[rows.count - 1] = last
            } else {
                rows.append([p])
            }
        }
        return rows.map { $0.sorted { $0.x < $1.x }.map(\.text).joined(separator: "  ") }
    }
}
