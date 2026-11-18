//
//  QuizQuestionScreen.swift
//  MowologyCRM
//
//  One pre-shift quiz question on one screen: header, clue, photo, four answers, next. The same view serves
//  the asking and the answered states, so the question, photo and rows never move when the answer lands.
//  The correct answer draws its own tick (QuizAnswerRow). Tim, 2026-11-18.
//

import SwiftUI
import UIKit

struct QuizQuestionScreen: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dynamicTypeSize) private var typeSize

    let question: QuizQuestionResponse
    let state: QuizAnswerState?
    let isBusy: Bool
    let onAnswer: (Int) -> Void
    let onNext: () -> Void

    /// The tapped row, shown at once while the server marks the answer.
    @State private var tapped: Int?
    @State private var viewerStart: Int?

    private var q: QuizQuestionDetail { question.question }
    private var answered: Bool { state != nil }
    private var hasPhoto: Bool { !q.images.isEmpty }

    var body: some View {
        // Flexible layout (the photo takes what is left) while the minimum fits; otherwise it scrolls, which is
        // what larger accessibility text sizes get.
        ViewThatFits(in: .vertical) {
            content(scrolling: false)
            ScrollView { content(scrolling: true) }
        }
        .fullScreenCover(item: Binding(get: { viewerStart.map { QuizViewerStart(index: $0) } },
                                       set: { viewerStart = $0?.index })) { start in
            QuizPhotoViewer(images: q.images, start: start.index)
        }
        #if DEBUG
        .task {   // design checks: `-mwQuizTap <option index>` taps that answer 2 s after the question shows
            let a = ProcessInfo.processInfo.arguments
            guard let i = a.firstIndex(of: "-mwQuizTap"), i + 1 < a.count, let n = Int(a[i + 1]), n < question.options.count else { return }
            try? await Task.sleep(nanoseconds: 2_000_000_000)
            choose(question.options[n])
            if a.contains("-mwQuizDoubleTap") { choose(question.options[n]); onAnswer(question.options[n].id) }   // must score once
        }
        #endif
        .onChange(of: state?.isCorrect) { _, correct in
            guard let correct else { return }
            // Said once, when the answer lands.
            let right = question.options.first { $0.id == state?.correctOptionId }?.optionText
            AccessibilityNotification.Announcement(correct ? "Correct" : "Incorrect." + (right.map { " The answer is \($0)." } ?? "")).post()
        }
    }

    private func content(scrolling: Bool) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            header
            if hasPhoto {
                clue
                photo(flexible: !scrolling)
            } else {
                clue.padding(.top, 8)       // text-only: the question leads, the answers sit under the thumb
                Spacer(minLength: 0)
            }
            VStack(spacing: 8) {
                ForEach(question.options) { option in row(option) }
            }
            nextSlot
        }
        .padding(.horizontal, 16)
        .padding(.top, 8)
        .padding(.bottom, 8)
    }

    // MARK: Header

    private var header: some View {
        // Completion only, so no percentage: it would say the same thing as the bar.
        let done = answered ? question.questionNum : question.questionNum - 1
        return VStack(spacing: 6) {
            HStack(alignment: .firstTextBaseline) {
                Text("Pre-Shift Quiz").font(appStyle.font(.headline, weight: .semibold))
                    .accessibilityAddTraits(.isHeader)
                Spacer()
                Text("\(question.questionNum) of \(question.total)")
                    .font(appStyle.font(.subheadline, weight: .semibold))
                    .foregroundStyle(appStyle.tenant)
                    .monospacedDigit()
            }
            GeometryReader { g in
                ZStack(alignment: .leading) {
                    Capsule().fill(appStyle.line)
                    Capsule().fill(appStyle.tenant)
                        .frame(width: g.size.width * CGFloat(done) / CGFloat(max(1, question.total)))
                        .animation(.easeOut(duration: 0.25), value: done)
                }
            }
            .frame(height: 3)
            .accessibilityElement()
            .accessibilityLabel("Progress")
            .accessibilityValue("\(done) of \(question.total) answered")
        }
    }

    // MARK: Question

    private var clue: some View {
        VStack(alignment: .leading, spacing: 3) {
            Text(q.categoryName.uppercased())
                .font(appStyle.font(.caption2, weight: .semibold))
                .tracking(0.6)
                .foregroundStyle(appStyle.meta)
            Text(q.text)
                .font(appStyle.font(hasPhoto ? .headline : .title2, weight: .semibold))
                .fixedSize(horizontal: false, vertical: true)
                .frame(maxWidth: .infinity, alignment: .leading)
        }
    }

    private func photo(flexible: Bool) -> some View {
        Group {
            if flexible {
                Color.clear.frame(minHeight: 120, maxHeight: .infinity)
                    .overlay { QuizImagePager(images: q.images, onOpen: { viewerStart = $0 }) }
            } else {
                QuizImagePager(images: q.images, onOpen: { viewerStart = $0 }).frame(height: 220)
            }
        }
    }

    // MARK: Answers

    private func row(_ option: QuizOption) -> some View {
        let mark: QuizAnswerRow.Mark
        if let state {
            if option.id == state.correctOptionId {
                mark = .correct(reward: option.id == state.optionId)
            } else if option.id == state.optionId {
                mark = .wrong
            } else { mark = .none }
        } else { mark = .none }
        return QuizAnswerRow(text: option.optionText, mark: mark,
                             selected: !answered && tapped == option.id,
                             locked: answered || tapped != nil || isBusy) { choose(option) }
    }

    private func choose(_ option: QuizOption) {
        guard !answered, tapped == nil else { return }   // a second tap does nothing
        tapped = option.id
        onAnswer(option.id)
    }

    /// Its place is kept from the first moment, so Next appears without moving anything.
    private var nextSlot: some View {
        Button(action: onNext) {
            Label("Next Question", systemImage: "arrow.right")
                .font(appStyle.font(.headline))
                .frame(maxWidth: .infinity, minHeight: 52)
                .background(appStyle.tenant)
                .foregroundStyle(appStyle.onTenant)
                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
        }
        .padding(.top, 4)
        .opacity(answered ? 1 : 0)
        .animation(.easeOut(duration: 0.2), value: answered)
        .allowsHitTesting(answered && !isBusy)
        .accessibilityHidden(!answered)
    }
}

private struct QuizViewerStart: Identifiable { let index: Int; var id: Int { index } }

// MARK: - One answer row

struct QuizAnswerRow: View {
    enum Mark: Equatable {
        case none
        case correct(reward: Bool)   // reward: the crew member picked it, so the tick is drawn
        case wrong
    }

    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.accessibilityReduceMotion) private var reduceMotion

    let text: String
    let mark: Mark
    let selected: Bool
    let locked: Bool
    let action: () -> Void

    // The tick's state. Settled values are the resting look.
    @State private var circle: CGFloat = 1
    @State private var tick: CGFloat = 1
    @State private var ring: CGFloat = 1
    @State private var ringOpacity: Double = 0
    @State private var tint: Double = 1
    @State private var played = false

    private static let slot: CGFloat = 28   // kept free on every row, so the text never rewraps

    var body: some View {
        Button(action: action) {
            HStack(spacing: 10) {
                Text(text)
                    .font(appStyle.font(.body))
                    .multilineTextAlignment(.leading)
                    .fixedSize(horizontal: false, vertical: true)
                    .frame(maxWidth: .infinity, alignment: .leading)
                markView.frame(width: Self.slot, height: Self.slot)
            }
            .padding(.horizontal, 14).padding(.vertical, 11)
            .frame(minHeight: 48)
            .background(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)).fill(appStyle.surface))
            .background(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)).fill(fill))
            .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)).stroke(outline, lineWidth: outlineWidth))
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .allowsHitTesting(!locked)   // not .disabled: that would grey the answered rows and the tick
        .accessibilityLabel(text)
        .accessibilityValue(accessibilityValue)
        .onAppear { if mark != .none { settle() } }
        .onChange(of: mark) { _, new in
            switch new {
            case .correct(reward: true): playReward()
            case .none: break
            default: settleSoftly()
            }
        }
    }

    // MARK: Look

    private var fill: Color {
        switch mark {
        case .correct(let reward): return appStyle.complete.opacity(reward ? 0.20 * tint : 0.08)
        case .wrong: return appStyle.blocked.opacity(0.10)
        case .none: return selected ? appStyle.tenant.opacity(0.08) : .clear
        }
    }
    private var outline: Color {
        switch mark {
        case .correct(let reward): return reward ? appStyle.complete.opacity(0.7) : appStyle.tenant.opacity(0.5)
        case .wrong: return appStyle.blocked.opacity(0.8)
        case .none: return selected ? appStyle.tenant : appStyle.tenant.opacity(0.3)
        }
    }
    private var outlineWidth: CGFloat { (mark != .none || selected) ? 1.5 : 1 }

    private var accessibilityValue: String {
        switch mark {
        case .correct(let reward): return reward ? "Your answer, correct" : "The correct answer"
        case .wrong: return "Your answer, incorrect"
        case .none: return ""
        }
    }

    @ViewBuilder private var markView: some View {
        switch mark {
        case .none:
            Color.clear
        case .correct:
            ZStack {
                Circle().stroke(appStyle.tenant.opacity(0.55), lineWidth: 1.5)
                    .scaleEffect(ring).opacity(ringOpacity)
                Circle().fill(appStyle.tenant).scaleEffect(circle)
                QuizTickShape().trim(from: 0, to: tick)
                    .stroke(Color.white, style: StrokeStyle(lineWidth: 2.8, lineCap: .round, lineJoin: .round))
                    .scaleEffect(circle)
            }
            .accessibilityHidden(true)
        case .wrong:
            ZStack {
                Circle().fill(appStyle.blocked)
                Image(systemName: "xmark").font(.system(size: 12, weight: .heavy)).foregroundStyle(.white)
            }
            .accessibilityHidden(true)
        }
    }

    // MARK: The reward — one gesture, about 0.45 s, once

    private func settle() { circle = 1; tick = 1; ring = 1; ringOpacity = 0; tint = 1; played = true }
    private func settleSoftly() { circle = 1; tick = 1; ring = 1; ringOpacity = 0; tint = 1; played = true }

    private func playReward() {
        guard !played else { return }
        played = true
        if reduceMotion {                                   // an immediate tick and a brief colour change
            circle = 1; tick = 1; ringOpacity = 0; tint = 0
            withAnimation(.easeOut(duration: 0.15)) { tint = 1 }
            QuizHaptics.success()
            return
        }
        circle = 0.5; tick = 0; ring = 1; ringOpacity = 0; tint = 0
        let t0 = CACurrentMediaTime()
        func log(_ what: String) {
            #if DEBUG
            print(String(format: "QUIZTICK %@ at %.0f ms", what, (CACurrentMediaTime() - t0) * 1000))
            #endif
        }
        log("circle starts")
        withAnimation(.easeOut(duration: 0.16)) { circle = 1; tint = 1 }          // 0.00  acknowledge
        Task { @MainActor in
            try? await Task.sleep(nanoseconds: 90_000_000)
            log("tick starts")
            withAnimation(.linear(duration: 0.20)) { tick = 1 }                      // 0.09  the mark is made
            try? await Task.sleep(nanoseconds: 200_000_000)
            log("tick complete + haptic")
            QuizHaptics.success()                                                    // 0.29  tick complete
            withAnimation(.easeOut(duration: 0.07)) { circle = 1.1 }
            ringOpacity = 0.7; ring = 1
            withAnimation(.easeOut(duration: 0.28)) { ring = 1.55; ringOpacity = 0 }
            try? await Task.sleep(nanoseconds: 70_000_000)
            withAnimation(.spring(response: 0.2, dampingFraction: 0.55)) { circle = 1 } // 0.36  settle
            log("settle starts")
        }
    }
}

/// Short downstroke, then the longer upward sweep.
struct QuizTickShape: Shape {
    func path(in r: CGRect) -> Path {
        var p = Path()
        p.move(to: CGPoint(x: r.minX + r.width * 0.28, y: r.minY + r.height * 0.52))
        p.addLine(to: CGPoint(x: r.minX + r.width * 0.43, y: r.minY + r.height * 0.67))
        p.addLine(to: CGPoint(x: r.minX + r.width * 0.73, y: r.minY + r.height * 0.35))
        return p
    }
}

enum QuizHaptics {
    static func success() {
        let g = UIImpactFeedbackGenerator(style: .light)
        g.impactOccurred(intensity: 0.8)
    }
}

// MARK: - Photos

/// The question's pictures: swipe between them, tap to inspect full screen; a site photo says where it grows.
struct QuizImagePager: View {
    @Environment(\.itAppStyle) private var appStyle
    let images: [QuizQuestionImage]
    let onOpen: (Int) -> Void
    @State private var page = 0

    var body: some View {
        ZStack(alignment: .bottomLeading) {
            RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)).fill(appStyle.sunk)
            TabView(selection: $page) {
                ForEach(Array(images.enumerated()), id: \.offset) { i, img in
                    AsyncImage(url: img.url) { phase in
                        switch phase {
                        case .success(let image): image.resizable().scaledToFit()
                        case .failure: Image(systemName: "photo").font(appStyle.font(.largeTitle)).foregroundStyle(appStyle.meta)
                        default: ProgressView()
                        }
                    }
                    .frame(maxWidth: .infinity, maxHeight: .infinity)
                    .contentShape(Rectangle())
                    .onTapGesture { onOpen(i) }
                    .accessibilityLabel(img.caption ?? "Question photo")
                    .accessibilityHint("Opens the photo full screen")
                    .accessibilityAddTraits(.isButton)
                    .tag(i)
                }
            }
            .tabViewStyle(.page(indexDisplayMode: images.count > 1 ? .always : .never))
            if let cap = images[min(page, images.count - 1)].caption, !cap.isEmpty {
                Label(cap, systemImage: "mappin.and.ellipse")
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.tenant)
                    .padding(.horizontal, 8).padding(.vertical, 4)
                    .background(.ultraThinMaterial, in: Capsule())
                    .padding(6)
            }
        }
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
    }
}

/// Full-screen inspection: pinch or double-tap to zoom, drag to move, swipe between photos.
struct QuizPhotoViewer: View {
    @Environment(\.dismiss) private var dismiss
    let images: [QuizQuestionImage]
    @State var start: Int

    init(images: [QuizQuestionImage], start: Int) { self.images = images; _start = State(initialValue: start) }

    var body: some View {
        ZStack(alignment: .topTrailing) {
            Color.black.ignoresSafeArea()
            TabView(selection: $start) {
                ForEach(Array(images.enumerated()), id: \.offset) { i, img in
                    ZoomableQuizImage(url: img.url).tag(i)
                }
            }
            .tabViewStyle(.page(indexDisplayMode: images.count > 1 ? .always : .never))
            .ignoresSafeArea()
            Button { dismiss() } label: {
                Image(systemName: "xmark.circle.fill").font(.system(size: 30)).symbolRenderingMode(.palette)
                    .foregroundStyle(.white, Color.black.opacity(0.5))
                    .padding(16)
            }
            .accessibilityLabel("Close photo")
        }
    }
}

private struct ZoomableQuizImage: View {
    let url: URL?
    @State private var scale: CGFloat = 1
    @State private var base: CGFloat = 1
    @State private var offset: CGSize = .zero
    @State private var dragBase: CGSize = .zero

    var body: some View {
        AsyncImage(url: url) { phase in
            switch phase {
            case .success(let image): image.resizable().scaledToFit()
            case .failure: Image(systemName: "photo").foregroundStyle(.white.opacity(0.6))
            default: ProgressView().tint(.white)
            }
        }
        .scaleEffect(scale)
        .offset(offset)
        .gesture(MagnifyGesture()
            .onChanged { scale = min(5, max(1, base * $0.magnification)) }
            .onEnded { _ in base = scale; if scale == 1 { offset = .zero; dragBase = .zero } })
        .simultaneousGesture(DragGesture()
            .onChanged { if scale > 1 { offset = CGSize(width: dragBase.width + $0.translation.width, height: dragBase.height + $0.translation.height) } }
            .onEnded { _ in dragBase = offset })
        .onTapGesture(count: 2) {
            withAnimation(.easeOut(duration: 0.2)) {
                if scale > 1 { scale = 1; base = 1; offset = .zero; dragBase = .zero } else { scale = 2.5; base = 2.5 }
            }
        }
        .accessibilityLabel("Question photo")
    }
}
