import Testing

/// Suites that drive `NavigationCoordinator.shared`. `.serialized` on a
/// suite only orders its own tests, so two serialized suites still run at
/// the same time and race on the singleton. Nesting them here runs every
/// one of their tests one at a time.
@Suite(.serialized)
enum SharedNavigationCoordinatorTests {}
