//
//  PhotoMetadata.swift
//  MowologyCRM
//
//  ImageIO helpers for the batch camera.
//
//  Why EXIF matters here: the server (MediaUploadService::mediaUploadFile) reads
//  DateTimeOriginal + GPS out of the JPEG into media_assets.captured_at / gps_*, then
//  strips the EXIF by re-encoding. captured_at is what stamps a visit's implied start /
//  completion from its before / after photo. `UIImage.jpegData()` drops all of it, so
//  camera shots keep the capture's own file data, and edited shots copy it back in.
//

import UIKit
import ImageIO
import CoreLocation
import UniformTypeIdentifiers

enum PhotoMetadata {

    /// Small, correctly-oriented preview decoded straight from the file (never the full bitmap).
    static func thumbnail(from data: Data, maxPixel: Int = BatchCameraLimits.previewMaxPixel) -> UIImage? {
        guard let src = CGImageSourceCreateWithData(data as CFData, nil) else { return nil }
        let opts: [CFString: Any] = [
            kCGImageSourceCreateThumbnailFromImageAlways: true,
            kCGImageSourceCreateThumbnailWithTransform: true,
            kCGImageSourceThumbnailMaxPixelSize: maxPixel,
        ]
        guard let cg = CGImageSourceCreateThumbnailAtIndex(src, 0, opts as CFDictionary) else { return nil }
        return UIImage(cgImage: cg)
    }

    /// The file's metadata dictionary (EXIF, TIFF, GPS, orientation …).
    static func properties(of data: Data) -> [String: Any] {
        guard let src = CGImageSourceCreateWithData(data as CFData, nil),
              let props = CGImageSourceCopyPropertiesAtIndex(src, 0, nil) as? [String: Any]
        else { return [:] }
        return props
    }

    /// Full-resolution image with the EXIF orientation applied (pixels upright, orientation .up).
    static func uprightImage(from data: Data) -> UIImage? {
        guard let image = UIImage(data: data) else { return nil }
        if image.imageOrientation == .up { return image }
        let format = UIGraphicsImageRendererFormat()
        format.scale = 1
        format.opaque = true
        return UIGraphicsImageRenderer(size: image.size, format: format).image { _ in
            image.draw(in: CGRect(origin: .zero, size: image.size))
        }
    }

    /// JPEG of an upright bitmap carrying `properties` (orientation reset to 1, since the
    /// pixels are already upright; pixel dimensions dropped so ImageIO writes the real ones).
    static func jpeg(from image: UIImage, properties: [String: Any],
                     quality: Double = BatchCameraLimits.jpegQuality) -> Data? {
        guard let cg = image.cgImage else { return nil }
        var props = properties
        props[kCGImagePropertyOrientation as String] = 1
        props.removeValue(forKey: kCGImagePropertyPixelWidth as String)
        props.removeValue(forKey: kCGImagePropertyPixelHeight as String)
        if var tiff = props[kCGImagePropertyTIFFDictionary as String] as? [String: Any] {
            tiff[kCGImagePropertyTIFFOrientation as String] = 1
            props[kCGImagePropertyTIFFDictionary as String] = tiff
        }
        if var exif = props[kCGImagePropertyExifDictionary as String] as? [String: Any] {
            exif.removeValue(forKey: kCGImagePropertyExifPixelXDimension as String)
            exif.removeValue(forKey: kCGImagePropertyExifPixelYDimension as String)
            props[kCGImagePropertyExifDictionary as String] = exif
        }
        props[kCGImageDestinationLossyCompressionQuality as String] = quality

        let out = NSMutableData()
        guard let dest = CGImageDestinationCreateWithData(out, UTType.jpeg.identifier as CFString, 1, nil)
        else { return nil }
        CGImageDestinationAddImage(dest, cg, props as CFDictionary)
        guard CGImageDestinationFinalize(dest) else { return nil }
        return out as Data
    }

    /// Metadata for a library/other image that has none: just the capture time.
    static func basicProperties(capturedAt: Date = Date()) -> [String: Any] {
        [kCGImagePropertyExifDictionary as String: [
            kCGImagePropertyExifDateTimeOriginal as String: exifDate(capturedAt),
            kCGImagePropertyExifDateTimeDigitized as String: exifDate(capturedAt),
        ]]
    }

    /// EXIF GPS dictionary for a fix (absolute values + N/S/E/W refs, UTC stamp).
    static func gpsDictionary(for location: CLLocation) -> [String: Any] {
        let c = location.coordinate
        let utc = DateFormatter()
        utc.locale = Locale(identifier: "en_US_POSIX")
        utc.timeZone = TimeZone(identifier: "UTC")
        utc.dateFormat = "yyyy:MM:dd"
        let date = utc.string(from: location.timestamp)
        utc.dateFormat = "HH:mm:ss.SS"
        let time = utc.string(from: location.timestamp)

        var gps: [String: Any] = [
            kCGImagePropertyGPSLatitude as String: abs(c.latitude),
            kCGImagePropertyGPSLatitudeRef as String: c.latitude >= 0 ? "N" : "S",
            kCGImagePropertyGPSLongitude as String: abs(c.longitude),
            kCGImagePropertyGPSLongitudeRef as String: c.longitude >= 0 ? "E" : "W",
            kCGImagePropertyGPSDateStamp as String: date,
            kCGImagePropertyGPSTimeStamp as String: time,
        ]
        if location.horizontalAccuracy >= 0 {
            gps[kCGImagePropertyGPSHPositioningError as String] = location.horizontalAccuracy
        }
        if location.verticalAccuracy >= 0 {
            gps[kCGImagePropertyGPSAltitude as String] = abs(location.altitude)
            gps[kCGImagePropertyGPSAltitudeRef as String] = location.altitude >= 0 ? 0 : 1
        }
        return gps
    }

    /// A recent, decent fix to stamp on a photo — the tracking service's latest while
    /// clocked in, else the OS's cached location. Never prompts, never waits.
    @MainActor
    static func recentLocation(maxAge: TimeInterval = 300, maxAccuracy: CLLocationAccuracy = 150) -> CLLocation? {
        let candidates = [GPSTrackingService.shared.locationManager.lastLocation,
                          CLLocationManager().location].compactMap { $0 }
        return candidates
            .filter { -$0.timestamp.timeIntervalSinceNow <= maxAge
                      && $0.horizontalAccuracy >= 0 && $0.horizontalAccuracy <= maxAccuracy }
            .max { $0.timestamp < $1.timestamp }
    }

    private static func exifDate(_ date: Date) -> String {
        let f = DateFormatter()
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy:MM:dd HH:mm:ss"
        return f.string(from: date)
    }
}
