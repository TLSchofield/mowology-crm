import SwiftUI
import UIKit

/// A receipt photo you can check: it opens zoomed to the receipt's width at the top (the print readable at once), and
/// pinch, drag and double-tap work as in Photos (UIScrollView). Used pinned at the top of the review sheet and full
/// screen (Tim, 2026-11-06: "the receipt in the viewport should be enlarged and movable", "it should be zoomed").
struct ReceiptZoomView: UIViewRepresentable {
    let image: UIImage
    var maxZoom: CGFloat = 6

    func makeCoordinator() -> Coordinator { Coordinator() }

    func makeUIView(context: Context) -> ReceiptScrollView {
        let scroll = ReceiptScrollView()
        scroll.delegate = context.coordinator
        scroll.showsHorizontalScrollIndicator = false
        scroll.showsVerticalScrollIndicator = false
        scroll.bouncesZoom = true
        scroll.decelerationRate = .fast
        scroll.contentInsetAdjustmentBehavior = .never
        scroll.backgroundColor = .clear
        scroll.maximumZoomScale = maxZoom
        scroll.imageView.image = image
        let doubleTap = UITapGestureRecognizer(target: context.coordinator, action: #selector(Coordinator.doubleTapped(_:)))
        doubleTap.numberOfTapsRequired = 2
        scroll.addGestureRecognizer(doubleTap)
        context.coordinator.scroll = scroll
        return scroll
    }

    func updateUIView(_ scroll: ReceiptScrollView, context: Context) {
        if scroll.imageView.image !== image {
            scroll.imageView.image = image
            scroll.needsFirstFit = true
            scroll.setNeedsLayout()
        }
        scroll.maximumZoomScale = maxZoom
    }

    final class Coordinator: NSObject, UIScrollViewDelegate {
        weak var scroll: ReceiptScrollView?

        func viewForZooming(in scrollView: UIScrollView) -> UIView? { (scrollView as? ReceiptScrollView)?.imageView }

        func scrollViewDidZoom(_ scrollView: UIScrollView) { (scrollView as? ReceiptScrollView)?.centreContent() }

        /// Double-tap: zoom in 2.5× on the tapped spot; again: back to the receipt's width.
        @objc func doubleTapped(_ g: UITapGestureRecognizer) {
            guard let scroll else { return }
            if scroll.zoomScale > scroll.widthScale * 1.05 {
                scroll.setZoomScale(scroll.widthScale, animated: true)
            } else {
                let point = g.location(in: scroll.imageView)
                let target = min(scroll.maximumZoomScale, scroll.widthScale * 2.5)
                let size = CGSize(width: scroll.bounds.width / target, height: scroll.bounds.height / target)
                scroll.zoom(to: CGRect(x: point.x - size.width / 2, y: point.y - size.height / 2,
                                       width: size.width, height: size.height), animated: true)
            }
        }
    }
}

/// The scroll view behind ReceiptZoomView: lays the photo out once it knows its own size.
final class ReceiptScrollView: UIScrollView {
    let imageView = UIImageView()
    var needsFirstFit = true
    private var fittedSize: CGSize = .zero
    /// The zoom at which the receipt fills the view's width.
    private(set) var widthScale: CGFloat = 1

    override init(frame: CGRect) {
        super.init(frame: frame)
        imageView.contentMode = .scaleToFill
        addSubview(imageView)
    }

    required init?(coder: NSCoder) { fatalError("init(coder:) is not used") }

    override func layoutSubviews() {
        super.layoutSubviews()
        guard let image = imageView.image, image.size.width > 0, image.size.height > 0,
              bounds.width > 0, bounds.height > 0 else { return }
        // Re-fit on the first layout, a new photo, or a size change (rotation, the sheet's height changing).
        guard needsFirstFit || fittedSize != bounds.size else { centreContent(); return }
        needsFirstFit = false
        fittedSize = bounds.size
        zoomScale = 1
        imageView.frame = CGRect(origin: .zero, size: image.size)
        contentSize = image.size
        let fitWhole = min(bounds.width / image.size.width, bounds.height / image.size.height)
        widthScale = bounds.width / image.size.width
        // Zoom out as far as the whole receipt, open at its width (readable), at the top.
        minimumZoomScale = min(fitWhole, widthScale)
        maximumZoomScale = max(maximumZoomScale, widthScale * 2)
        zoomScale = widthScale
        contentOffset = .zero
        centreContent()
    }

    /// Keep a photo smaller than the view centred instead of stuck in the top-left corner.
    func centreContent() {
        let dx = max(0, (bounds.width - contentSize.width) / 2)
        let dy = max(0, (bounds.height - contentSize.height) / 2)
        contentInset = UIEdgeInsets(top: dy, left: dx, bottom: dy, right: dx)
    }
}
