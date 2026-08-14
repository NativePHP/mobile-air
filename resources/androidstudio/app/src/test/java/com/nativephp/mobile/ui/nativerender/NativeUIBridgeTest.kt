package com.nativephp.mobile.ui.nativerender

import androidx.compose.runtime.snapshotFlow
import androidx.compose.runtime.snapshots.Snapshot
import kotlinx.coroutines.CoroutineStart
import kotlinx.coroutines.flow.take
import kotlinx.coroutines.flow.toList
import kotlinx.coroutines.launch
import kotlinx.coroutines.runBlocking
import kotlinx.coroutines.yield
import org.junit.Assert.assertEquals
import org.junit.Assert.assertSame
import org.junit.Test

class NativeUIBridgeTest {
    @Test
    fun identicalTreePublicationsAdvanceTheObservableRevisionIndependently() = runBlocking {
        val tree = NativeUITree(
            version = 0,
            callbackCount = 0,
            root = NativeUINode(
                id = 1,
                type = "column",
                layout = null,
                style = null,
                props = GenericProps(),
                onPress = 0,
                onLongPress = 0,
                children = emptyList(),
            ),
        )
        val initialPublicationId = NativeUIBridge.treePublicationId.longValue
        val observedPublicationIds = mutableListOf<Long>()
        val observation = launch(start = CoroutineStart.UNDISPATCHED) {
            snapshotFlow { NativeUIBridge.treePublicationId.longValue }
                .take(3)
                .toList(observedPublicationIds)
        }

        NativeUIBridge.publishTree(tree)
        Snapshot.sendApplyNotifications()
        yield()
        NativeUIBridge.publishTree(tree)
        Snapshot.sendApplyNotifications()
        observation.join()

        assertSame(tree, NativeUIBridge.currentTree.value)
        assertEquals(
            listOf(initialPublicationId, initialPublicationId + 1L, initialPublicationId + 2L),
            observedPublicationIds,
        )
    }
}
