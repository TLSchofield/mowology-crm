//
//  AppointmentCardView.swift
//  MowologyCRM
//
//  Quote visits on the day list (2026-10-15). Sent by /api/schedule/day as `appointments`,
//  beside the job stops and never mixed into them (no visit, no timer, no invoice).
//  The card: kind + time, client, address, who's going; Directions and Call.
//

import SwiftUI
import MapKit

struct Appointment: Codable, Identifiable, Hashable {
    let appointmentId: Int
    let kindLabel: String
    let date: String
    let time: String
    let timeLabel: String
    let durationMinutes: Int
    let contactName: String?
    let contactPhone: String?
    let propertyAddress: String
    let propertyCity: String
    let latitude: Double?
    let longitude: Double?
    let assignedName: String?
    let notes: String?

    var id: Int { appointmentId }

    enum CodingKeys: String, CodingKey {
        case appointmentId = "appointment_id"
        case kindLabel = "kind_label"
        case date, time
        case timeLabel = "time_label"
        case durationMinutes = "duration_minutes"
        case contactName = "contact_name"
        case contactPhone = "contact_phone"
        case propertyAddress = "property_address"
        case propertyCity = "property_city"
        case latitude, longitude
        case assignedName = "assigned_name"
        case notes
    }
}

struct AppointmentCardView: View {
    let appointment: Appointment
    @Environment(\.openURL) private var openURL

    var body: some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack(spacing: 8) {
                Text(appointment.kindLabel.uppercased())
                    .font(.caption2.weight(.heavy))
                    .padding(.horizontal, 7).padding(.vertical, 2)
                    .background(Color.MW.green)
                    .foregroundStyle(.white)
                    .clipShape(Capsule())
                Text(appointment.timeLabel)
                    .font(.subheadline.weight(.bold))
                    .foregroundStyle(Color.MW.forest)
                Spacer()
                Text("\(appointment.durationMinutes) min")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            if let name = appointment.contactName, !name.isEmpty {
                Text(name).font(.headline).foregroundStyle(Color.MW.forest)
            }
            Text(addressLine)
                .font(.subheadline)
                .foregroundStyle(.secondary)
            if let who = appointment.assignedName {
                Label(who, systemImage: "person")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            if let notes = appointment.notes, !notes.isEmpty {
                Text(notes).font(.caption).foregroundStyle(.secondary)
            }
            HStack(spacing: 10) {
                Button { openDirections() } label: {
                    Label("Directions", systemImage: "car.fill").font(.subheadline.weight(.semibold))
                }
                .buttonStyle(.borderedProminent)
                .tint(Color.MW.green)
                if let phone = appointment.contactPhone, !phone.isEmpty {
                    Button { call(phone) } label: {
                        Label("Call", systemImage: "phone.fill").font(.subheadline.weight(.semibold))
                    }
                    .buttonStyle(.bordered)
                    .tint(Color.MW.green)
                }
            }
            .padding(.top, 2)
        }
        .padding(12)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.MW.green.opacity(0.08))
        .overlay(
            RoundedRectangle(cornerRadius: 12)
                .strokeBorder(Color.MW.green, style: StrokeStyle(lineWidth: 1, dash: [5, 3]))
        )
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    private var addressLine: String {
        appointment.propertyCity.isEmpty ? appointment.propertyAddress : "\(appointment.propertyAddress), \(appointment.propertyCity)"
    }

    private func openDirections() {
        let item: MKMapItem
        if let lat = appointment.latitude, let lng = appointment.longitude {
            item = MKMapItem(placemark: MKPlacemark(coordinate: CLLocationCoordinate2D(latitude: lat, longitude: lng)))
        } else if let url = URL(string: "http://maps.apple.com/?daddr=" + (addressLine.addingPercentEncoding(withAllowedCharacters: .urlQueryAllowed) ?? "")) {
            openURL(url)
            return
        } else {
            return
        }
        item.name = appointment.contactName ?? addressLine
        item.openInMaps(launchOptions: [MKLaunchOptionsDirectionsModeKey: MKLaunchOptionsDirectionsModeDriving])
    }

    private func call(_ phone: String) {
        let digits = phone.filter { $0.isNumber || $0 == "+" }
        if let url = URL(string: "tel:\(digits)") { openURL(url) }
    }
}
