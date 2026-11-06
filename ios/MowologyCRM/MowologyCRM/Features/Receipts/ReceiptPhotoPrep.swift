import CoreImage
import CoreImage.CIFilterBuiltins
import UIKit
import Vision

/// Finds the receipt's edges in a camera photo, crops and straightens it, and measures whether it is good
/// enough to read (fills the frame, roughly square-on, sharp). The server reads cleaner text from the crop;
/// a weak photo gets the "Hold flat, fill the frame" hint with Retake / Use this photo. Best effort: when no
/// receipt edges are found the photo is kept as it was.
enum ReceiptPhotoPrep {
    static let hintText = "Hold flat, fill the frame"

    /// The receipt's longer side should cover at least this share of the photo's matching side.
    static let minFill = 0.5
    /// Largest corner-angle departure from square before the photo counts as skewed.
    static let maxSkewDegrees = 22.0
    /// Variance of the Laplacian on a 600 px wide grey copy; below this the text is soft.
    /// Measured on synthetic receipt text at 1000 px wide (Mac harness, 2026-11-06): sharp 520–1430, a 1 px
    /// Gaussian blur 78–250, 2 px (barely legible) 11–29. Not yet checked on real crew photos, so it sits
    /// low: only clearly blurred photos get the hint, and the crew can always use the photo anyway.
    static let minSharpness = 40.0
    /// Below this the segmentation guess is not trusted to crop.
    static let minConfidence: Float = 0.6

    struct Outcome {
        let image: UIImage
        let cropped: Bool
        let fill: Double
        let skewDegrees: Double
        let sharpness: Double

        var isBlurry: Bool { sharpness < ReceiptPhotoPrep.minSharpness }
        var isSkewed: Bool { skewDegrees > ReceiptPhotoPrep.maxSkewDegrees }
        var isSmall: Bool { fill < ReceiptPhotoPrep.minFill }
        var needsHint: Bool { isBlurry || isSkewed || isSmall }
    }

    /// Longest side worked on: enough for the 1920 px upload, far lighter than a 12 MP original.
    private static let workingMaxDim: CGFloat = 2600
    private static let ciContext = CIContext(options: [.useSoftwareRenderer: false])

    static func prepare(_ photo: UIImage) -> Outcome {
        let upright = uprightCopy(photo)
        guard let cg = upright.cgImage else {
            return Outcome(image: photo, cropped: false, fill: 1, skewDegrees: 0, sharpness: .greatestFiniteMagnitude)
        }
        let w = CGFloat(cg.width), h = CGFloat(cg.height)

        var quad: VNRectangleObservation?
        let request = VNDetectDocumentSegmentationRequest()
        do {
            try VNImageRequestHandler(cgImage: cg, options: [:]).perform([request])
            quad = request.results?.first
        } catch {
            quad = nil
        }

        guard let q = quad, q.confidence >= minConfidence else {
            return Outcome(image: upright, cropped: false, fill: 0, skewDegrees: 0, sharpness: sharpness(of: cg))
        }

        // Corners in pixels, origin bottom-left (Vision's and Core Image's frame alike).
        let corners = expanded([q.topLeft, q.topRight, q.bottomRight, q.bottomLeft], by: 0.015)
            .map { CGPoint(x: min(max($0.x, 0), 1) * w, y: min(max($0.y, 0), 1) * h) }
        let xs = corners.map(\.x), ys = corners.map(\.y)
        let fill = max(Double((xs.max()! - xs.min()!) / w), Double((ys.max()! - ys.min()!) / h))
        let skew = maxCornerSkew(corners)

        let filter = CIFilter.perspectiveCorrection()
        filter.inputImage = CIImage(cgImage: cg)
        filter.topLeft = corners[0]
        filter.topRight = corners[1]
        filter.bottomRight = corners[2]
        filter.bottomLeft = corners[3]
        guard let out = filter.outputImage,
              out.extent.width >= 200, out.extent.height >= 200,
              let croppedCG = ciContext.createCGImage(out, from: out.extent) else {
            return Outcome(image: upright, cropped: false, fill: fill, skewDegrees: skew, sharpness: sharpness(of: cg))
        }
        return Outcome(image: UIImage(cgImage: croppedCG), cropped: true, fill: fill, skewDegrees: skew,
                       sharpness: sharpness(of: croppedCG))
    }

    /// The photo drawn upright (camera photos carry their rotation as a flag) and no larger than the working size.
    private static func uprightCopy(_ image: UIImage) -> UIImage {
        let size = image.size
        let scale = min(1, workingMaxDim / max(size.width, size.height))
        let target = CGSize(width: (size.width * scale).rounded(), height: (size.height * scale).rounded())
        let format = UIGraphicsImageRendererFormat.default()
        format.scale = 1
        return UIGraphicsImageRenderer(size: target, format: format).image { _ in
            image.draw(in: CGRect(origin: .zero, size: target))
        }
    }

    /// Push each corner a little away from the centre so the crop never shaves the receipt's printed edge.
    private static func expanded(_ pts: [CGPoint], by f: CGFloat) -> [CGPoint] {
        let cx = pts.map(\.x).reduce(0, +) / CGFloat(pts.count)
        let cy = pts.map(\.y).reduce(0, +) / CGFloat(pts.count)
        return pts.map { CGPoint(x: $0.x + ($0.x - cx) * f * 2, y: $0.y + ($0.y - cy) * f * 2) }
    }

    /// Largest departure of any corner angle from 90°, in degrees (0 for a photo taken square-on).
    static func maxCornerSkew(_ c: [CGPoint]) -> Double {
        var worst = 0.0
        for i in 0..<c.count {
            let p = c[i], a = c[(i + c.count - 1) % c.count], b = c[(i + 1) % c.count]
            let v1 = CGVector(dx: a.x - p.x, dy: a.y - p.y), v2 = CGVector(dx: b.x - p.x, dy: b.y - p.y)
            let len = hypot(v1.dx, v1.dy) * hypot(v2.dx, v2.dy)
            guard len > 0 else { continue }
            let cosA = max(-1, min(1, (v1.dx * v2.dx + v1.dy * v2.dy) / len))
            worst = max(worst, abs(acos(Double(cosA)) * 180 / .pi - 90))
        }
        return worst
    }

    /// Variance of the Laplacian over a 600 px wide grey copy: high for crisp print, low for a blurred photo.
    static func sharpness(of image: CGImage) -> Double {
        let width = 600
        let height = max(1, min(3000, Int(Double(image.height) * Double(width) / Double(max(image.width, 1)))))
        var pixels = [UInt8](repeating: 0, count: width * height)
        let drawn: Bool = pixels.withUnsafeMutableBytes { buf in
            guard let ctx = CGContext(data: buf.baseAddress, width: width, height: height, bitsPerComponent: 8,
                                      bytesPerRow: width, space: CGColorSpaceCreateDeviceGray(),
                                      bitmapInfo: CGImageAlphaInfo.none.rawValue) else { return false }
            ctx.interpolationQuality = .medium
            ctx.draw(image, in: CGRect(x: 0, y: 0, width: width, height: height))
            return true
        }
        guard drawn, width > 2, height > 2 else { return .greatestFiniteMagnitude }
        var sum = 0.0, sumSq = 0.0, n = 0.0
        for y in 1..<(height - 1) {
            let row = y * width
            for x in 1..<(width - 1) {
                let i = row + x
                let lap = 4 * Double(pixels[i]) - Double(pixels[i - 1]) - Double(pixels[i + 1])
                    - Double(pixels[i - width]) - Double(pixels[i + width])
                sum += lap
                sumSq += lap * lap
                n += 1
            }
        }
        let mean = sum / n
        return sumSq / n - mean * mean
    }
}
