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
        /// Each recognised line with its box, as JSON for `client_ocr_lines` (DeviceOcrCompare.php
        /// cleanDeviceOcrLines()): the server rebuilds the receipt's rows from them, so a name read in one
        /// block and its price in another still pair. Nil when there was nothing to send.
        var linesJSON: String? = nil
    }

    /// A read already in flight, started by the caller while the GPS fix is still settling.
    struct Prefetched {
        let task: Task<Result?, Never>
        init(_ imageData: Data) { task = Task(priority: .userInitiated) { await DeviceTextScanner.read(imageData) } }
        /// For a capture that leaves without uploading.
        func cancel() { task.cancel() }
    }

    /// Longest we let the on-device read hold the upload.
    private static let timeoutSeconds: Double = 6

    static func read(_ imageData: Data) async -> Result? {
        guard let cgImage = UIImage(data: imageData)?.cgImage else { return nil }
        // Race Vision against the clock with a resume-once guard: the caller stops waiting after
        // `timeoutSeconds` even if Vision never returns (a task group would have to join it).
        // The Vision request itself is cancelled on timeout or when the caller's task is cancelled
        // (Prefetched.cancel), so a stalled read does not keep working in the background.
        let job = VisionJob()
        return await withTaskCancellationHandler {
            await withCheckedContinuation { (continuation: CheckedContinuation<Result?, Never>) in
                let gate = ResumeOnce(continuation)
                job.onFinish = { gate.resume($0) }
                recognise(cgImage, job: job)
                DispatchQueue.global().asyncAfter(deadline: .now() + timeoutSeconds) {
                    job.cancel()
                    gate.resume(nil)
                }
            }
        } onCancel: {
            job.cancel()
        }
    }

    /// One Vision read: holds the request so it can be cancelled, and reports its result exactly once.
    private final class VisionJob {
        private let lock = NSLock()
        private var request: VNRequest?
        private var cancelled = false
        private var finish: ((Result?) -> Void)?
        var onFinish: ((Result?) -> Void)? {
            get { lock.lock(); defer { lock.unlock() }; return finish }
            set {
                lock.lock()
                let alreadyCancelled = cancelled
                finish = newValue
                lock.unlock()
                if alreadyCancelled { done(nil) }
            }
        }
        /// Registers the request; false when the job was already cancelled (do not start it).
        func start(_ r: VNRequest) -> Bool {
            lock.lock(); defer { lock.unlock() }
            if cancelled { return false }
            request = r
            return true
        }
        func cancel() {
            lock.lock()
            cancelled = true
            let r = request
            lock.unlock()
            r?.cancel()
            done(nil)
        }
        func done(_ value: Result?) {
            lock.lock()
            let f = finish
            finish = nil
            lock.unlock()
            f?(value)
        }
    }

    private final class ResumeOnce {
        private let lock = NSLock()
        private var continuation: CheckedContinuation<Result?, Never>?
        init(_ c: CheckedContinuation<Result?, Never>) { continuation = c }
        func resume(_ value: Result?) {
            lock.lock()
            let c = continuation
            continuation = nil
            lock.unlock()
            c?.resume(returning: value)
        }
    }

    private static func recognise(_ cgImage: CGImage, job: VisionJob) {
        DispatchQueue.global(qos: .userInitiated).async {
            let request = VNRecognizeTextRequest()
            request.recognitionLevel = .accurate
            request.usesLanguageCorrection = false   // prices and codes, not prose
            guard job.start(request) else { job.done(nil); return }
            let handler = VNImageRequestHandler(cgImage: cgImage, options: [:])
            do {
                try handler.perform([request])
            } catch {
                job.done(nil)
                return
            }
            let observations = (request.results ?? [])
            // Top of the receipt first; Vision's y runs bottom-up. Lines at (nearly) the same height
            // stay on one line, left to right, so "TOTAL   23.28" is not split across lines.
            let rows = Self.rows(from: observations)
            let text = rows.joined(separator: "\n")
            let lines = Self.linesJSON(from: observations, width: cgImage.width, height: cgImage.height)
            job.done(text.isEmpty ? nil : Result(text: text, version: "r\(request.revision)", linesJSON: lines))
        }
    }

    /// `{"v":1,"image":{"w":…,"h":…},"lines":[{"text","x","y","w","h","deg"}]}` — x/y/w/h as 0–1 fractions with
    /// the origin top-left (Vision's runs bottom-up, so y is flipped), deg the line's tilt, positive when its right
    /// end sits lower in the photo.
    static func linesJSON(from observations: [VNRecognizedTextObservation], width: Int, height: Int) -> String? {
        let w = Double(max(width, 1)), h = Double(max(height, 1))
        let lines: [[String: Any]] = observations.compactMap { o in
            guard let top = o.topCandidates(1).first, !top.string.isEmpty else { return nil }
            let b = o.boundingBox
            let dx = Double(o.topRight.x - o.topLeft.x) * w
            let dyUp = Double(o.topRight.y - o.topLeft.y) * h
            let deg = dx > 0 ? -atan2(dyUp, dx) * 180 / .pi : 0
            func r(_ v: Double) -> Double { (v * 10000).rounded() / 10000 }
            return ["text": top.string, "x": r(Double(b.minX)), "y": r(1 - Double(b.maxY)),
                    "w": r(Double(b.width)), "h": r(Double(b.height)), "deg": (deg * 100).rounded() / 100]
        }
        guard lines.count >= 2 else { return nil }
        let payload: [String: Any] = ["v": 1, "image": ["w": width, "h": height], "lines": lines]
        guard let data = try? JSONSerialization.data(withJSONObject: payload) else { return nil }
        return String(data: data, encoding: .utf8)
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
