import SwiftUI
import WidgetKit

struct NativePHPWidgetEntry: TimelineEntry {
    let date: Date
}

struct NativePHPWidgetProvider: TimelineProvider {
    func placeholder(in context: Context) -> NativePHPWidgetEntry {
        NativePHPWidgetEntry(date: .now)
    }

    func getSnapshot(in context: Context, completion: @escaping (NativePHPWidgetEntry) -> Void) {
        completion(NativePHPWidgetEntry(date: .now))
    }

    func getTimeline(in context: Context, completion: @escaping (Timeline<NativePHPWidgetEntry>) -> Void) {
        completion(Timeline(entries: [NativePHPWidgetEntry(date: .now)], policy: .never))
    }
}

struct NativePHPWidgetView: View {
    var entry: NativePHPWidgetEntry

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            Image(systemName: "shippingbox.fill")
                .font(.title2)
            Spacer()
            Text("NativePHP")
                .font(.headline)
            Text("Widget extensions work")
                .font(.caption)
                .foregroundStyle(.secondary)
        }
        .containerBackground(for: .widget) {
            LinearGradient(
                colors: [.indigo.opacity(0.35), .pink.opacity(0.2)],
                startPoint: .topLeading,
                endPoint: .bottomTrailing
            )
        }
    }
}

struct NativePHPWidget: Widget {
    let kind = "NativePHPWidget"

    var body: some WidgetConfiguration {
        StaticConfiguration(kind: kind, provider: NativePHPWidgetProvider()) { entry in
            NativePHPWidgetView(entry: entry)
        }
        .configurationDisplayName("NativePHP Widget")
        .description("A minimal WidgetKit extension compiled from a NativePHP plugin.")
        .supportedFamilies([.systemSmall, .systemMedium])
    }
}

@main
struct NativePHPWidgetBundle: WidgetBundle {
    var body: some Widget {
        NativePHPWidget()
    }
}
