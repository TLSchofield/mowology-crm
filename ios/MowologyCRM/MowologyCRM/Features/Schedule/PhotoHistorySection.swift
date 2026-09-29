//
//  PhotoHistorySection.swift
//  MowologyCRM
//
//  "What did this site look like last time?" — photos from earlier visits at the
//  same property, newest visit first, on the stop the crew is standing at.
//  GET /api/schedule/visit-photos?visit_id=N&mode=history
//

import SwiftUI

// MARK: - Models

struct PhotoHistoryVisit: Decodable, Identifiable {
    let visitId: Int
    let date: String
    let service: String
    let status: String
    let photos: [VisitPhoto]

    var id: Int { visitId }

    enum CodingKeys: String, CodingKey {
        case visitId = "visit_id"
        case date, service, status, photos
    }
}

struct PhotoHistoryResponse: Decodable {
    let success: Bool
    let history: [PhotoHistoryVisit]?
}

extension VisitPhoto {
    /// Full-size photo. Paths come back web-root relative.
    var fullURL: URL? {
        if photoUrl.hasPrefix("http") { return URL(string: photoUrl) }
        return URL(string: "https://mowology.ca" + photoUrl)
    }
}

/// One photo in the full-screen viewer, with the visit it came from.
private struct HistoryPhotoRef: Identifiable {
    let photo: VisitPhoto
    let caption: String
    var id: Int { photo.id }
}

// MARK: - Section

struct PhotoHistorySection: View {

    /// Any visit on the stop — the server resolves the property from it.
    let visitId: Int
    let apiClient: APIClient

    @State private var history: [PhotoHistoryVisit] = []
    @State private var hasLoaded = false
    @State private var isExpanded = false
    @State private var viewerStart: HistoryPhotoRef?

    /// Visits shown before "Show all" is tapped.
    private static let collapsedCount = 2

    private static let isoFormatter: DateFormatter = {
        let f = DateFormatter()
        f.dateFormat = "yyyy-MM-dd"
        f.locale     = Locale(identifier: "en_CA")
        return f
    }()

    private var allPhotos: [HistoryPhotoRef] {
        history.flatMap { visit in
            visit.photos.map { HistoryPhotoRef(photo: $0, caption: Self.caption(for: visit, photo: $0)) }
        }
    }

    private var photoCount: Int { history.reduce(0) { $0 + $1.photos.count } }

    var body: some View {
        // Nothing to show is the normal case for a new client — take no space at all.
        Group {
            if !history.isEmpty {
                VStack(alignment: .leading, spacing: 12) {
                    HStack {
                        Label("Photo History", systemImage: "photo.on.rectangle.angled")
                            .font(.subheadline.weight(.semibold))
                        Spacer()
                        Text("\(photoCount) photo\(photoCount == 1 ? "" : "s")")
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }

                    ForEach(isExpanded ? history : Array(history.prefix(Self.collapsedCount))) { visit in
                        visitRow(visit)
                    }

                    if history.count > Self.collapsedCount {
                        Button {
                            withAnimation(.easeInOut(duration: 0.2)) { isExpanded.toggle() }
                        } label: {
                            Text(isExpanded ? "Show less" : "Show all \(history.count) visits")
                                .font(.footnote.weight(.semibold))
                                .foregroundStyle(Color.MW.green)
                        }
                    }
                }
                .padding(14)
                .background(Color(.systemBackground))
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }
        }
        .task {
            guard !hasLoaded else { return }
            hasLoaded = true
            await load()
        }
        .fullScreenCover(item: $viewerStart) { start in
            PhotoHistoryViewer(photos: allPhotos, startId: start.id)
        }
    }

    // MARK: - Rows

    private func visitRow(_ visit: PhotoHistoryVisit) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 6) {
                Text(Self.dateLabel(visit.date))
                    .font(.caption.weight(.semibold))
                Text("·").foregroundStyle(.secondary)
                Text(visit.service)
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)
                Spacer()
                if let ago = Self.agoLabel(visit.date) {
                    Text(ago)
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                }
            }

            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 8) {
                    ForEach(visit.photos) { photo in
                        Button {
                            viewerStart = HistoryPhotoRef(photo: photo,
                                                          caption: Self.caption(for: visit, photo: photo))
                        } label: {
                            thumbnail(photo)
                        }
                        .buttonStyle(.plain)
                    }
                }
            }
        }
    }

    private func thumbnail(_ photo: VisitPhoto) -> some View {
        AsyncImage(url: photo.thumbnailURL) { phase in
            if let img = phase.image {
                img.resizable().scaledToFill()
            } else {
                Color(.systemGray6)
            }
        }
        .frame(width: 76, height: 76)
        .clipShape(RoundedRectangle(cornerRadius: 8))
        .overlay(alignment: .bottomLeading) {
            if photo.photoType == "before" || photo.photoType == "after" {
                Text(photo.photoType.capitalized)
                    .font(.system(size: 9, weight: .bold))
                    .foregroundStyle(.white)
                    .padding(.horizontal, 5)
                    .padding(.vertical, 2)
                    .background(.black.opacity(0.55), in: Capsule())
                    .padding(4)
            }
        }
    }

    // MARK: - Load

    /// Silent on failure — offline just means no history strip this time.
    @MainActor
    private func load() async {
        guard let response: PhotoHistoryResponse =
                try? await apiClient.request(.scheduleVisitPhotoHistory(visitId: visitId)) else { return }
        history = (response.history ?? []).filter { !$0.photos.isEmpty }
    }

    // MARK: - Formatting

    private static func dateLabel(_ iso: String) -> String {
        guard let d = isoFormatter.date(from: iso) else { return iso }
        let sameYear = Calendar.current.isDate(d, equalTo: Date(), toGranularity: .year)
        return sameYear
            ? d.formatted(.dateTime.weekday(.abbreviated).month(.abbreviated).day())
            : d.formatted(.dateTime.month(.abbreviated).day().year())
    }

    private static func agoLabel(_ iso: String) -> String? {
        guard let d = isoFormatter.date(from: iso) else { return nil }
        let days = Calendar.current.dateComponents(
            [.day], from: Calendar.current.startOfDay(for: d), to: Calendar.current.startOfDay(for: Date())
        ).day ?? 0
        switch days {
        case ..<0:    return nil
        case 0:       return "today"
        case 1:       return "yesterday"
        case 2..<60:  return "\(days) days ago"
        case 60..<365: return "\(days / 30) months ago"
        default:      return "\(days / 365) yr ago"
        }
    }

    private static func caption(for visit: PhotoHistoryVisit, photo: VisitPhoto) -> String {
        var parts = [dateLabel(visit.date), visit.service]
        if photo.photoType == "before" || photo.photoType == "after" {
            parts.append(photo.photoType.capitalized)
        }
        return parts.joined(separator: " · ")
    }
}

// MARK: - Full-screen viewer

/// Swipe through every history photo for the property; pinch to zoom.
private struct PhotoHistoryViewer: View {

    let photos: [HistoryPhotoRef]
    let startId: Int

    @Environment(\.dismiss) private var dismiss
    @State private var currentId: Int = 0

    var body: some View {
        ZStack(alignment: .top) {
            Color.black.ignoresSafeArea()

            TabView(selection: $currentId) {
                ForEach(photos) { ref in
                    ZoomablePhoto(url: ref.photo.fullURL)
                        .tag(ref.id)
                }
            }
            .tabViewStyle(.page(indexDisplayMode: .never))
            .ignoresSafeArea()

            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 2) {
                    Text(photos.first { $0.id == currentId }?.caption ?? "")
                        .font(.subheadline.weight(.semibold))
                    if let index = photos.firstIndex(where: { $0.id == currentId }) {
                        Text("\(index + 1) of \(photos.count)")
                            .font(.caption)
                            .opacity(0.8)
                    }
                }
                .foregroundStyle(.white)

                Spacer()

                Button { dismiss() } label: {
                    Image(systemName: "xmark")
                        .font(.body.weight(.bold))
                        .foregroundStyle(.white)
                        .frame(width: 40, height: 40)
                        .background(.white.opacity(0.18), in: Circle())
                }
                .accessibilityLabel("Close")
            }
            .padding(.horizontal, 16)
            .padding(.top, 8)
            .background(
                LinearGradient(colors: [.black.opacity(0.6), .clear], startPoint: .top, endPoint: .bottom)
                    .ignoresSafeArea(edges: .top)
            )
        }
        .onAppear { currentId = startId }
    }
}

private struct ZoomablePhoto: View {

    let url: URL?

    @State private var scale: CGFloat = 1
    @State private var lastScale: CGFloat = 1

    var body: some View {
        AsyncImage(url: url) { phase in
            switch phase {
            case .success(let img):
                img.resizable()
                    .scaledToFit()
                    .scaleEffect(scale)
                    .gesture(
                        MagnificationGesture()
                            .onChanged { scale = min(max(lastScale * $0, 1), 5) }
                            .onEnded { _ in lastScale = scale }
                    )
                    .onTapGesture(count: 2) {
                        withAnimation(.spring(response: 0.3, dampingFraction: 0.8)) {
                            scale = scale > 1 ? 1 : 2.5
                            lastScale = scale
                        }
                    }
            case .failure:
                Label("Photo unavailable", systemImage: "photo.badge.exclamationmark")
                    .foregroundStyle(.white.opacity(0.7))
            default:
                ProgressView().tint(.white)
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
    }
}
