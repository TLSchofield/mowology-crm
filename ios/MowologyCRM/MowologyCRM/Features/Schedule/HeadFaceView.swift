//
//  HeadFaceView.swift
//  MowologyCRM
//
//  A department head's face (Yui, Otto, …) from the same URL the Team tab uses
//  (TeamHead.faceURL → https://mowology.ca/crm/img/heads/<slug>.jpg), kept in a small disk
//  cache so the special-request screen still shows the face with no signal. Falls back to the
//  head's initial on a green disc.
//

import SwiftUI
import UIKit

@MainActor
final class HeadFaceCache {
    static let shared = HeadFaceCache()

    private var memory: [String: UIImage] = [:]
    private var loading: Set<String> = []

    private var directory: URL? {
        guard let base = FileManager.default.urls(for: .cachesDirectory, in: .userDomainMask).first else { return nil }
        let dir = base.appendingPathComponent("head-faces", isDirectory: true)
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir
    }

    static func url(for slug: String) -> URL? {
        TeamHead.all.first { $0.slug == slug }?.faceURL
            ?? URL(string: "https://mowology.ca/crm/img/heads/\(slug).jpg")
    }

    /// Memory, then disk. Never touches the network.
    func cached(_ slug: String) -> UIImage? {
        if let img = memory[slug] { return img }
        guard let file = directory?.appendingPathComponent("\(slug).jpg"),
              let data = try? Data(contentsOf: file),
              let img = UIImage(data: data) else { return nil }
        memory[slug] = img
        return img
    }

    /// Fetch and keep on disk (refreshes a cached copy too). Silent on failure.
    func load(_ slug: String) async -> UIImage? {
        if loading.contains(slug) { return cached(slug) }
        loading.insert(slug)
        defer { loading.remove(slug) }
        guard let url = Self.url(for: slug),
              let (data, response) = try? await URLSession.shared.data(from: url),
              (response as? HTTPURLResponse)?.statusCode == 200,
              let img = UIImage(data: data) else { return cached(slug) }
        memory[slug] = img
        if let file = directory?.appendingPathComponent("\(slug).jpg") {
            try? data.write(to: file, options: .atomic)
        }
        return img
    }
}

struct HeadFaceView: View {
    let slug: String
    let name: String
    let size: CGFloat

    @State private var image: UIImage?

    var body: some View {
        ZStack {
            Circle().fill(Color.MW.forest)
            if let image {
                Image(uiImage: image)
                    .resizable()
                    .scaledToFill()
            } else {
                Text(String(name.prefix(1)).uppercased())
                    .font(.system(size: size * 0.42, weight: .bold))
                    .foregroundStyle(.white)
            }
        }
        .frame(width: size, height: size)
        .clipShape(Circle())
        .overlay(Circle().stroke(Color.MW.orange, lineWidth: size > 60 ? 4 : 2))
        .accessibilityLabel(Text(name))
        .task(id: slug) {
            image = HeadFaceCache.shared.cached(slug)
            if let fresh = await HeadFaceCache.shared.load(slug) { image = fresh }
        }
    }
}
