import Testing
import SwiftUI
@testable import NativePHP

private func pluginInitializerCompilationFixture() {
    NativeTabPresentationRegistry.shared.register(identifier: "fixture:initializer") { _ in
        AnyView(EmptyView())
    }
}

struct NativePHPTests {

    @Test func example() async throws {
        // Write your test here and use APIs like `#expect(...)` to check expected conditions.
    }

    @Test func tabPresentationRegistryIsDeterministic() async throws {
        let registry = NativeTabPresentationRegistry.shared
        registry.resetForTesting()
        #expect(registry.register(identifier: "acme:floating") { _ in AnyView(EmptyView()) })
        #expect(!registry.register(identifier: "acme:floating") { _ in AnyView(Text("second")) })
        #expect(registry.presenter(for: "acme:floating") != nil)
        #expect(registry.presenter(for: "missing") == nil)
        registry.resetForTesting()
    }

    @Test func tabPresentationResolutionFallsBackUnlessAValidPresenterResolves() throws {
        let registry = NativeTabPresentationRegistry.shared
        registry.resetForTesting()
        defer { registry.resetForTesting() }

        let configuration = try #require(NativeTabPresentationConfiguration(json: "{}"))
        #expect(registry.resolve(identifier: "", configuration: nil) == nil)
        #expect(registry.resolve(identifier: "system", configuration: configuration) == nil)
        #expect(registry.resolve(identifier: "example:missing", configuration: configuration) == nil)

        #expect(registry.register(identifier: "example:available") { _ in AnyView(EmptyView()) })
        #expect(registry.resolve(identifier: "example:available", configuration: nil) == nil)
        #expect(registry.resolve(identifier: "example:available", configuration: configuration) != nil)
    }

    @Test func tabPresentationConfigurationAndActivationAreValidated() async throws {
        let configuration = try #require(NativeTabPresentationConfiguration(
            json: #"{"radius":24,"glass":true,"labels":["Home","Profile"]}"#
        ))
        #expect(configuration.number("radius") == 24)
        #expect(configuration.bool("glass") == true)
        #expect(configuration.number("glass") == nil)
        #expect(configuration.bool("radius") == nil)
        #expect(configuration.strings("labels") == ["Home", "Profile"])
        #expect(NativeTabPresentationConfiguration(json: "[1,2]") == nil)

        var activated: [String] = []
        let context = NativeTabPresentationContext(
            tabs: [NativeTabDescriptor(
                id: "home", label: "Home", accessibilityLabel: "Home", icon: "house",
                badge: nil, showsNews: false, role: .navigation
            )],
            selectedTabIdentifier: "home",
            configuration: configuration,
            isHidden: false,
            activate: { activated.append($0) }
        )
        context.activate("unknown")
        context.activate("home")
        #expect(activated == ["home"])
    }

    @Test func tabPresentationIdentifiersDisambiguateDuplicateSourceIdentifiers() {
        #expect(nativeTabPresentationIdentifier(sourceIdentifier: "home", index: 0) == "home#0")
        #expect(nativeTabPresentationIdentifier(sourceIdentifier: "home", index: 1) == "home#1")
    }

    @Test func tabPresentationSelectionTracksTheLiveSelectedTab() {
        let identifiers = ["home", "profile"]
        #expect(nativeTabPresentationSelectedIdentifier(
            sourceIdentifiers: identifiers,
            selectedSourceIdentifier: "profile",
            fallbackIndex: 0
        ) == "profile#1")
        #expect(nativeTabPresentationSelectedIdentifier(
            sourceIdentifiers: identifiers,
            selectedSourceIdentifier: "missing",
            fallbackIndex: 0
        ) == "home#0")
    }

    @Test func customPresentationUsesEffectiveSystemTabVisibility() {
        #expect(!nativeTabPresentationIsHidden(explicitlyHidden: false, hasPushedDestination: false))
        #expect(nativeTabPresentationIsHidden(explicitlyHidden: true, hasPushedDestination: false))
        #expect(nativeTabPresentationIsHidden(explicitlyHidden: false, hasPushedDestination: true))
    }

    @Test func pluginInitializerCanAccessPublicRegistry() {
        NativeTabPresentationRegistry.shared.resetForTesting()
        pluginInitializerCompilationFixture()
        #expect(NativeTabPresentationRegistry.shared.presenter(for: "fixture:initializer") != nil)
        NativeTabPresentationRegistry.shared.resetForTesting()
    }

}
