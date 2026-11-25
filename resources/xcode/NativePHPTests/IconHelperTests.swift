import Testing
@testable import NativePHP

struct IconHelperTests {

    /// Real SF Symbol names that the alias table used to swap for a
    /// different symbol, mostly their `.fill` variant (#650).
    @Test(arguments: [
        "bell", "bolt", "bookmark", "camera", "cart", "clock", "folder", "heart",
        "lightbulb", "lock", "map", "message", "pause", "person", "phone", "photo",
        "receipt", "star", "stop", "video", "info", "mail", "location", "speaker",
        "forward", "repeat", "smartphone",
    ])
    func anSFSymbolNameDrawsAsItself(_ name: String) {
        #expect(getIconForName(name) == name)
    }

    @Test func aliasesThatAreNotSFSymbolsStillMap() {
        #expect(getIconForName("home") == "house.fill")
        #expect(getIconForName("settings") == "gearshape.fill")
        #expect(getIconForName("favorite") == "heart.fill")
        #expect(getIconForName("heart_outline") == "heart")
        #expect(getIconForName("notifications") == "bell.fill")
        #expect(getIconForName("chevron_right") == "chevron.right")
    }

    @Test func aDottedNameIsUsedAsWritten() {
        #expect(getIconForName("heart.fill") == "heart.fill")
        #expect(getIconForName("car.side.fill") == "car.side.fill")
    }

    /// SF Symbol names are lower-case, so a capitalised alias still maps.
    @Test func aCapitalisedNameStillUsesTheAliasTable() {
        #expect(getIconForName("Heart") == "heart.fill")
    }
}
