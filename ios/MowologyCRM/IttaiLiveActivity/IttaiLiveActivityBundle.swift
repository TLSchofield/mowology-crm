/// Registers Ittai's clocked-in Live Activity widget so an active shift can remain visible at the top of the phone while the app is backgrounded, following the behavior admired in Jobber.

import SwiftUI
import WidgetKit

@main
struct IttaiLiveActivityBundle: WidgetBundle {
    var body: some Widget {
        ClockLiveActivity()
    }
}
