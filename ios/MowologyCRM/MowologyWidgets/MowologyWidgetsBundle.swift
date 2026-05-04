//
//  MowologyWidgetsBundle.swift
//  MowologyWidgets
//
//  Widget extension entry point. Combines:
//    - JobActivityWidget    — Live Activity (Lock Screen + Dynamic Island)
//    - ClockStatusWidget    — Home/Lock Screen clock-in status
//    - TodayScheduleWidget  — Today's stop count + next address
//

import SwiftUI
import WidgetKit

@main
struct MowologyWidgetsBundle: WidgetBundle {
    var body: some Widget {
        JobActivityWidget()
        ClockStatusWidget()
        TodayScheduleWidget()
    }
}
