package com.nativephp.mobile.ui

import android.content.Context
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.horizontalScroll
import androidx.compose.foundation.layout.*
import androidx.compose.foundation.lazy.LazyColumn
import androidx.compose.foundation.lazy.items
import androidx.compose.foundation.lazy.rememberLazyListState
import androidx.compose.foundation.rememberScrollState
import androidx.compose.foundation.shape.CircleShape
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.material3.*
import androidx.compose.runtime.*
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.clip
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextOverflow
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import android.util.Log
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch
import java.io.File

enum class LogLevel(val label: String, val color: Color) {
    EMERGENCY("EMERGENCY", Color(0xFFFF1744)),
    ALERT("ALERT", Color(0xFFFF5252)),
    CRITICAL("CRITICAL", Color(0xFFFF1744)),
    ERROR("ERROR", Color(0xFFEF5350)),
    WARNING("WARNING", Color(0xFFFFA726)),
    NOTICE("NOTICE", Color(0xFF42A5F5)),
    INFO("INFO", Color(0xFF66BB6A)),
    DEBUG("DEBUG", Color(0xFF78909C));

    companion object {
        fun fromString(level: String): LogLevel? {
            return entries.find { level.contains(it.label, ignoreCase = true) }
        }
    }
}

data class LogEntry(
    val timestamp: String,
    val level: LogLevel,
    val message: String,
    val stackTrace: String? = null
)

private val LOG_PATTERN = Regex(
    """^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}[.\d]*[+\-\d:]*)\]\s+\w+\.(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG):\s+(.*)""",
    RegexOption.DOT_MATCHES_ALL
)

private fun parseLogFile(content: String): List<LogEntry> {
    val entries = mutableListOf<LogEntry>()
    val lines = content.lines()
    var i = 0

    while (i < lines.size) {
        val line = lines[i]
        val match = LOG_PATTERN.find(line)
        if (match != null) {
            val timestamp = match.groupValues[1]
            val level = LogLevel.fromString(match.groupValues[2]) ?: LogLevel.DEBUG
            val message = match.groupValues[3].trim()

            // Collect stack trace lines (lines that don't start a new log entry)
            val traceLines = mutableListOf<String>()
            i++
            while (i < lines.size && !LOG_PATTERN.containsMatchIn(lines[i])) {
                if (lines[i].isNotBlank()) {
                    traceLines.add(lines[i])
                }
                i++
            }

            entries.add(
                LogEntry(
                    timestamp = timestamp,
                    level = level,
                    message = message,
                    stackTrace = traceLines.takeIf { it.isNotEmpty() }?.joinToString("\n")
                )
            )
        } else {
            i++
        }
    }

    return entries
}

private enum class DebugTab(val label: String) {
    LOGS("Logs"),
    SCHEDULER("Scheduler")
}

data class ScheduledJob(
    val command: String,
    val intervalMinutes: Long,
    val constraints: Map<String, Any>,
    val status: String,
    val nextRunTimeMillis: Long = 0L
)

/**
 * Query WorkManager's internal SQLite database directly to find all scheduled work.
 * This avoids any compile-time dependency on work-runtime-ktx.
 */
private fun loadScheduledJobs(context: Context): List<ScheduledJob> {
    val TAG = "DebugLogViewer"
    val jobs = mutableListOf<ScheduledJob>()

    try {
        // WorkManager stores its database in the no_backup directory
        val dbFile = java.io.File(context.noBackupFilesDir, "androidx.work.workdb")
        if (!dbFile.exists()) {
            Log.d(TAG, "WorkManager database not found at ${dbFile.absolutePath}")
            return emptyList()
        }

        val db = android.database.sqlite.SQLiteDatabase.openDatabase(
            dbFile.absolutePath, null, android.database.sqlite.SQLiteDatabase.OPEN_READONLY
        )

        // Query work specs joined with tags, including constraint and timing columns
        val cursor = db.rawQuery("""
            SELECT ws.id, ws.state, ws.interval_duration,
                   ws.last_enqueue_time, ws.schedule_requested_at,
                   ws.required_network_type, ws.requires_charging,
                   ws.requires_battery_not_low, ws.requires_device_idle,
                   GROUP_CONCAT(wt.tag, '||') as tags
            FROM WorkSpec ws
            LEFT JOIN WorkTag wt ON ws.id = wt.work_spec_id
            GROUP BY ws.id
            ORDER BY ws.schedule_requested_at DESC
        """.trimIndent(), null)

        Log.d(TAG, "WorkManager DB: ${cursor.count} work item(s)")

        while (cursor.moveToNext()) {
            val id = cursor.getString(0)
            val stateInt = cursor.getInt(1)
            val intervalMs = cursor.getLong(2)
            val lastEnqueueTime = cursor.getLong(3)
            val scheduleRequestedAt = cursor.getLong(4)
            val requiredNetworkType = cursor.getInt(5)
            val requiresCharging = cursor.getInt(6) != 0
            val requiresBatteryNotLow = cursor.getInt(7) != 0
            val requiresDeviceIdle = cursor.getInt(8) != 0
            val tagsRaw = cursor.getString(9) ?: ""
            val tags = tagsRaw.split("||").filter { it.isNotEmpty() }

            val status = when (stateInt) {
                0 -> "ENQUEUED"
                1 -> "RUNNING"
                2 -> "SUCCEEDED"
                3 -> "FAILED"
                4 -> "BLOCKED"
                5 -> "CANCELLED"
                else -> "UNKNOWN($stateInt)"
            }

            // Extract command name from tags
            val commandTag = tags.firstOrNull { it.startsWith("nativephp_scheduled_") && !it.contains("immediate_") }
            val command = commandTag?.removePrefix("nativephp_scheduled_")
                ?: tags.firstOrNull { !it.contains(".") }
                ?: tags.firstOrNull()
                ?: id.take(8)

            val intervalMinutes = if (intervalMs > 0) intervalMs / 60000 else 0L

            // Calculate next run time from last enqueue + interval
            val nextRunTimeMillis = if (intervalMs > 0 && lastEnqueueTime > 0) {
                lastEnqueueTime + intervalMs
            } else 0L

            // Build constraints map
            val constraintMap = mutableMapOf<String, Any>()
            if (requiredNetworkType > 0) {
                val networkName = when (requiredNetworkType) {
                    1 -> "CONNECTED"
                    2 -> "UNMETERED"
                    3 -> "NOT_ROAMING"
                    4 -> "METERED"
                    else -> "TYPE_$requiredNetworkType"
                }
                constraintMap["network"] = networkName
            }
            if (requiresCharging) constraintMap["charging"] = true
            if (requiresBatteryNotLow) constraintMap["battery"] = true
            if (requiresDeviceIdle) constraintMap["idle"] = true

            Log.d(TAG, "Work: $command state=$status interval=${intervalMinutes}min next=${nextRunTimeMillis} constraints=$constraintMap")

            jobs.add(ScheduledJob(command, intervalMinutes, constraintMap, status, nextRunTimeMillis))
        }

        cursor.close()
        db.close()

        Log.d(TAG, "Found ${jobs.size} work item(s)")
    } catch (e: Exception) {
        Log.e(TAG, "Failed to query WorkManager DB: ${e.javaClass.name}: ${e.message}", e)
    }

    return jobs
}

@Composable
fun DebugLogFab(onClick: () -> Unit) {
    FloatingActionButton(
        onClick = onClick,
        modifier = Modifier.size(40.dp),
        shape = CircleShape,
        containerColor = Color(0xCC333333),
        contentColor = Color.White,
        elevation = FloatingActionButtonDefaults.elevation(2.dp)
    ) {
        Text(
            text = "\uD83D\uDC1B",
            fontSize = 18.sp
        )
    }
}

@OptIn(ExperimentalMaterial3Api::class)
@Composable
fun DebugLogSheet(
    context: Context,
    visible: Boolean,
    onDismiss: () -> Unit
) {
    if (!visible) return

    val sheetState = rememberModalBottomSheetState(skipPartiallyExpanded = false)
    var activeTab by remember { mutableStateOf(DebugTab.LOGS) }

    ModalBottomSheet(
        onDismissRequest = onDismiss,
        sheetState = sheetState,
        containerColor = Color(0xFF1E1E1E),
        contentColor = Color(0xFFD4D4D4),
        dragHandle = {
            Column(
                horizontalAlignment = Alignment.CenterHorizontally,
                modifier = Modifier.fillMaxWidth()
            ) {
                Spacer(Modifier.height(8.dp))
                Box(
                    Modifier
                        .width(32.dp)
                        .height(4.dp)
                        .clip(RoundedCornerShape(2.dp))
                        .background(Color(0xFF666666))
                )
                Spacer(Modifier.height(8.dp))

                // Tab row
                Row(
                    modifier = Modifier
                        .fillMaxWidth()
                        .padding(horizontal = 16.dp),
                    horizontalArrangement = Arrangement.spacedBy(8.dp)
                ) {
                    DebugTab.entries.forEach { tab ->
                        val isActive = tab == activeTab
                        Text(
                            tab.label,
                            color = if (isActive) Color.White else Color(0xFF888888),
                            fontSize = 14.sp,
                            fontWeight = FontWeight.Bold,
                            modifier = Modifier
                                .clip(RoundedCornerShape(6.dp))
                                .background(if (isActive) Color(0xFF333333) else Color.Transparent)
                                .clickable { activeTab = tab }
                                .padding(horizontal = 14.dp, vertical = 6.dp)
                        )
                    }
                }
                Spacer(Modifier.height(8.dp))
                HorizontalDivider(color = Color(0xFF333333))
            }
        }
    ) {
        when (activeTab) {
            DebugTab.LOGS -> LogsTabContent(context)
            DebugTab.SCHEDULER -> JobsTabContent(context)
        }
    }
}

@Composable
private fun LogsTabContent(context: Context) {
    val listState = rememberLazyListState()

    var allEntries by remember { mutableStateOf<List<LogEntry>>(emptyList()) }
    var activeFilters by remember { mutableStateOf(LogLevel.entries.toSet()) }
    var autoScroll by remember { mutableStateOf(true) }
    var lastFileLength by remember { mutableLongStateOf(0L) }

    val filteredEntries = remember(allEntries, activeFilters) {
        allEntries.filter { it.level in activeFilters }
    }

    val logFile = remember {
        val appStorageDir = context.getDir("storage", Context.MODE_PRIVATE)
        File(appStorageDir, "persisted_data/storage/logs/laravel.log")
    }

    // Tail the log file
    LaunchedEffect(logFile) {
        while (true) {
            try {
                if (logFile.exists()) {
                    val currentLength = logFile.length()
                    if (currentLength != lastFileLength) {
                        val content = logFile.readText()
                        allEntries = parseLogFile(content)
                        lastFileLength = currentLength
                    }
                }
            } catch (_: Exception) {}
            delay(1500)
        }
    }

    // Auto-scroll to bottom when new entries arrive
    LaunchedEffect(filteredEntries.size) {
        if (autoScroll && filteredEntries.isNotEmpty()) {
            listState.animateScrollToItem(filteredEntries.size - 1)
        }
    }

    Column(modifier = Modifier.fillMaxWidth()) {
        // Header
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 16.dp, vertical = 4.dp),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text(
                "Laravel Log",
                color = Color.White,
                fontSize = 16.sp,
                fontWeight = FontWeight.Bold
            )
            Row(verticalAlignment = Alignment.CenterVertically) {
                Text(
                    "${filteredEntries.size} entries",
                    color = Color(0xFF888888),
                    fontSize = 12.sp
                )
                Spacer(Modifier.width(12.dp))
                // Auto-scroll toggle
                Text(
                    if (autoScroll) "TAIL" else "PAUSED",
                    color = if (autoScroll) Color(0xFF66BB6A) else Color(0xFF888888),
                    fontSize = 11.sp,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier
                        .clip(RoundedCornerShape(4.dp))
                        .background(if (autoScroll) Color(0xFF1B3A1B) else Color(0xFF333333))
                        .clickable { autoScroll = !autoScroll }
                        .padding(horizontal = 8.dp, vertical = 4.dp)
                )
                Spacer(Modifier.width(8.dp))
                // Clear
                Text(
                    "CLEAR",
                    color = Color(0xFFEF5350),
                    fontSize = 11.sp,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier
                        .clip(RoundedCornerShape(4.dp))
                        .background(Color(0xFF3A1B1B))
                        .clickable {
                            try {
                                logFile.writeText("")
                                allEntries = emptyList()
                                lastFileLength = 0L
                            } catch (_: Exception) {}
                        }
                        .padding(horizontal = 8.dp, vertical = 4.dp)
                )
            }
        }

        Spacer(Modifier.height(4.dp))

        // Filter chips
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .horizontalScroll(rememberScrollState())
                .padding(horizontal = 12.dp),
            horizontalArrangement = Arrangement.spacedBy(6.dp)
        ) {
            LogLevel.entries.forEach { level ->
                val isActive = level in activeFilters
                val count = allEntries.count { it.level == level }
                Text(
                    "${level.label} $count",
                    color = if (isActive) level.color else Color(0xFF555555),
                    fontSize = 10.sp,
                    fontWeight = FontWeight.Bold,
                    modifier = Modifier
                        .clip(RoundedCornerShape(4.dp))
                        .background(
                            if (isActive) level.color.copy(alpha = 0.15f)
                            else Color(0xFF2A2A2A)
                        )
                        .clickable {
                            activeFilters = if (isActive) {
                                activeFilters - level
                            } else {
                                activeFilters + level
                            }
                        }
                        .padding(horizontal = 8.dp, vertical = 4.dp)
                )
            }
        }

        Spacer(Modifier.height(8.dp))
        HorizontalDivider(color = Color(0xFF333333))

        if (filteredEntries.isEmpty()) {
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .height(200.dp),
                contentAlignment = Alignment.Center
            ) {
                Text(
                    if (allEntries.isEmpty()) "No log entries" else "No entries match filters",
                    color = Color(0xFF666666),
                    fontSize = 14.sp
                )
            }
        } else {
            LazyColumn(
                state = listState,
                modifier = Modifier
                    .fillMaxWidth()
                    .fillMaxHeight(0.85f)
                    .padding(horizontal = 8.dp),
                contentPadding = PaddingValues(bottom = 32.dp)
            ) {
                items(filteredEntries.size) { index ->
                    LogEntryRow(filteredEntries[index])
                }
            }
        }
    }
}

@Composable
private fun JobsTabContent(context: Context) {
    var jobs by remember { mutableStateOf<List<ScheduledJob>>(emptyList()) }
    var loading by remember { mutableStateOf(true) }

    // Load jobs and refresh periodically
    LaunchedEffect(Unit) {
        while (true) {
            jobs = loadScheduledJobs(context)
            loading = false
            delay(5000)
        }
    }

    Column(modifier = Modifier.fillMaxWidth()) {
        // Header
        Row(
            modifier = Modifier
                .fillMaxWidth()
                .padding(horizontal = 16.dp, vertical = 4.dp),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically
        ) {
            Text(
                "Scheduler",
                color = Color.White,
                fontSize = 16.sp,
                fontWeight = FontWeight.Bold
            )
            Text(
                "${jobs.size} command(s)",
                color = Color(0xFF888888),
                fontSize = 12.sp
            )
        }

        Spacer(Modifier.height(8.dp))
        HorizontalDivider(color = Color(0xFF333333))

        if (loading) {
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .height(200.dp),
                contentAlignment = Alignment.Center
            ) {
                CircularProgressIndicator(
                    color = Color(0xFF66BB6A),
                    modifier = Modifier.size(24.dp),
                    strokeWidth = 2.dp
                )
            }
        } else if (jobs.isEmpty()) {
            Box(
                modifier = Modifier
                    .fillMaxWidth()
                    .height(200.dp),
                contentAlignment = Alignment.Center
            ) {
                Column(horizontalAlignment = Alignment.CenterHorizontally) {
                    Text(
                        "No scheduled commands",
                        color = Color(0xFF666666),
                        fontSize = 14.sp
                    )
                    Spacer(Modifier.height(4.dp))
                    Text(
                        "Define commands in routes/console.php with Schedule::command()",
                        color = Color(0xFF555555),
                        fontSize = 11.sp
                    )
                }
            }
        } else {
            LazyColumn(
                modifier = Modifier
                    .fillMaxWidth()
                    .fillMaxHeight(0.85f)
                    .padding(horizontal = 8.dp),
                contentPadding = PaddingValues(bottom = 32.dp)
            ) {
                items(jobs.size) { index ->
                    JobRow(jobs[index])
                }
            }
        }
    }
}

@Composable
private fun JobRow(job: ScheduledJob) {
    val statusColor = when (job.status) {
        "ENQUEUED" -> Color(0xFF42A5F5)
        "RUNNING" -> Color(0xFFFFA726)
        "SUCCEEDED" -> Color(0xFF66BB6A)
        "FAILED" -> Color(0xFFEF5350)
        "CANCELLED" -> Color(0xFF888888)
        else -> Color(0xFF888888)
    }

    Column(
        modifier = Modifier
            .fillMaxWidth()
            .padding(vertical = 8.dp, horizontal = 4.dp)
    ) {
        Row(
            modifier = Modifier.fillMaxWidth(),
            horizontalArrangement = Arrangement.SpaceBetween,
            verticalAlignment = Alignment.CenterVertically
        ) {
            // Command name
            Text(
                job.command,
                color = Color(0xFFD4D4D4),
                fontSize = 13.sp,
                fontWeight = FontWeight.Medium,
                fontFamily = FontFamily.Monospace,
                modifier = Modifier.weight(1f)
            )

            // Status badge
            Text(
                job.status,
                color = statusColor,
                fontSize = 9.sp,
                fontWeight = FontWeight.Bold,
                fontFamily = FontFamily.Monospace,
                modifier = Modifier
                    .clip(RoundedCornerShape(3.dp))
                    .background(statusColor.copy(alpha = 0.12f))
                    .padding(horizontal = 6.dp, vertical = 2.dp)
            )
        }

        Spacer(Modifier.height(4.dp))

        // Interval + next run
        Row(
            horizontalArrangement = Arrangement.spacedBy(12.dp)
        ) {
            Text(
                "every ${job.intervalMinutes}min",
                color = Color(0xFF888888),
                fontSize = 10.sp,
                fontFamily = FontFamily.Monospace
            )

            // Next run countdown
            if (job.nextRunTimeMillis > 0) {
                val remaining = job.nextRunTimeMillis - System.currentTimeMillis()
                val nextRunText = if (remaining > 0) {
                    val mins = remaining / 60000
                    val secs = (remaining % 60000) / 1000
                    "next in ${mins}m ${secs}s"
                } else {
                    "due now"
                }
                Text(
                    nextRunText,
                    color = Color(0xFF42A5F5),
                    fontSize = 10.sp,
                    fontFamily = FontFamily.Monospace
                )
            }

            // Constraints
            if (job.constraints.isNotEmpty()) {
                val constraintText = job.constraints.keys.joinToString(", ")
                Text(
                    constraintText,
                    color = Color(0xFF666666),
                    fontSize = 10.sp,
                    fontFamily = FontFamily.Monospace
                )
            }
        }

        HorizontalDivider(color = Color(0xFF2A2A2A), modifier = Modifier.padding(top = 8.dp))
    }
}

@Composable
private fun LogEntryRow(entry: LogEntry) {
    var expanded by remember { mutableStateOf(false) }

    Column(
        modifier = Modifier
            .fillMaxWidth()
            .clickable(enabled = entry.stackTrace != null) { expanded = !expanded }
            .padding(vertical = 4.dp, horizontal = 4.dp)
    ) {
        Row(verticalAlignment = Alignment.Top) {
            // Level badge
            Text(
                entry.level.label.take(3),
                color = entry.level.color,
                fontSize = 9.sp,
                fontWeight = FontWeight.Bold,
                fontFamily = FontFamily.Monospace,
                modifier = Modifier
                    .clip(RoundedCornerShape(2.dp))
                    .background(entry.level.color.copy(alpha = 0.12f))
                    .padding(horizontal = 4.dp, vertical = 1.dp)
            )
            Spacer(Modifier.width(6.dp))
            // Timestamp
            Text(
                entry.timestamp.substringAfter("T").substringAfter(" ").take(8),
                color = Color(0xFF666666),
                fontSize = 10.sp,
                fontFamily = FontFamily.Monospace
            )
            Spacer(Modifier.width(6.dp))
            // Message
            Text(
                entry.message,
                color = Color(0xFFD4D4D4),
                fontSize = 11.sp,
                fontFamily = FontFamily.Monospace,
                maxLines = if (expanded) Int.MAX_VALUE else 2,
                overflow = TextOverflow.Ellipsis,
                modifier = Modifier.weight(1f)
            )
            // Stack trace indicator
            if (entry.stackTrace != null) {
                Text(
                    if (expanded) " \u25B2" else " \u25BC",
                    color = Color(0xFF666666),
                    fontSize = 10.sp
                )
            }
        }

        // Stack trace
        if (expanded && entry.stackTrace != null) {
            Text(
                entry.stackTrace,
                color = Color(0xFF888888),
                fontSize = 9.sp,
                fontFamily = FontFamily.Monospace,
                modifier = Modifier
                    .fillMaxWidth()
                    .padding(start = 36.dp, top = 4.dp)
                    .clip(RoundedCornerShape(4.dp))
                    .background(Color(0xFF151515))
                    .padding(8.dp)
                    .horizontalScroll(rememberScrollState())
            )
        }

        HorizontalDivider(color = Color(0xFF2A2A2A), modifier = Modifier.padding(top = 4.dp))
    }
}
