import SwiftUI

struct AppUpdateView: View {
    @Environment(\.itAppStyle) private var appStyle
    let result: VersionCheckResult

    var body: some View {
        ZStack {
            Color.MW.forest
                .ignoresSafeArea()

            VStack(spacing: 0) {
                Image(systemName: "arrow.down.app.fill")
                    .font(appStyle.font(size: 64))
                    .foregroundColor(Color.MW.lime)
                    .padding(.bottom, 20)

                Text("Update Required")
                    .font(appStyle.font(size: 26, weight: .bold))
                    .foregroundColor(Color.MW.lime)
                    .padding(.bottom, 8)

                if !result.latestVersion.isEmpty {
                    Text("Mowology CRM v\(result.latestVersion) is now available.")
                        .font(appStyle.font(size: 16))
                        .foregroundColor(Color(red: 0.784, green: 0.910, blue: 0.867))
                        .multilineTextAlignment(.center)
                        .padding(.horizontal, 32)
                        .padding(.bottom, 16)
                }

                if let notes = result.releaseNotes, !notes.isEmpty {
                    Text(notes)
                        .font(appStyle.font(size: 14))
                        .foregroundColor(Color(red: 0.576, green: 0.788, blue: 0.722))
                        .multilineTextAlignment(.center)
                        .padding(.horizontal, 40)
                        .padding(.bottom, 28)
                } else {
                    Spacer().frame(height: 28)
                }

                if let storeUrl = result.storeUrl, !storeUrl.isEmpty,
                   let url = URL(string: storeUrl) {
                    Link("Update on App Store", destination: url)
                        .font(appStyle.font(size: 17, weight: .semibold))
                        .frame(minWidth: 220)
                        .padding(.vertical, 14)
                        .padding(.horizontal, 36)
                        .background(Color.MW.lime)
                        .foregroundColor(Color.MW.forest)
                        .cornerRadius(appStyle.cornerRadius(12))
                } else {
                    VStack(spacing: 6) {
                        Text("Contact Tim to get the latest build.")
                            .font(appStyle.font(size: 15))
                            .foregroundColor(Color(red: 0.576, green: 0.788, blue: 0.722))
                            .multilineTextAlignment(.center)
                        Text("(778) 846-9273")
                            .font(appStyle.font(size: 17, weight: .semibold))
                            .foregroundColor(Color.MW.lime)
                    }
                    .padding(.horizontal, 40)
                }

                Text("You must update to continue.")
                    .font(appStyle.font(size: 12))
                    .foregroundColor(Color(red: 0.353, green: 0.533, blue: 0.431))
                    .padding(.top, 24)
            }
            .padding(.horizontal, 24)
        }
    }
}
