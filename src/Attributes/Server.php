<?php

namespace Native\Mobile\Attributes;

use Attribute;

/**
 * Marks a method as a `#[Server]` method — one whose body runs on the
 * client and whose real work happens on a server
 * (docs/ASGARD-SERVER-METHODS.md §1).
 *
 *     #[Server]
 *     public function refreshChart(string $range): Closure
 *     {
 *         // Runs on the CLIENT, before anything is sent.
 *         $this->loading = true;
 *         $this->range = $range;
 *
 *         // Runs on the CLIENT, when the server answers.
 *         return function (ServerResponse $response) {
 *             $this->loading = false;
 *         };
 *     }
 *
 * The server half is an ordinary method of the same name on the server
 * component, also carrying this attribute. Two implementations sharing a
 * name: the client one is a local pre-step (optimistic update, loading
 * flag, validation), the server one is the remote work.
 *
 * The attribute is also the allowlist (§8). A method without it is not
 * callable from this path on either side, which is what keeps the
 * callable surface from being "every public method the component
 * happens to have" once a name arrives over a wire.
 *
 * It deliberately takes no arguments. A supersede/debounce policy for
 * overlapping calls is the obvious first one to want and is explicitly
 * out of v1 (§6): compare-and-set reconciliation (§5.2) makes concurrent
 * calls safe without making them ordered, so the policy is an
 * optimisation rather than a correctness fix.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Server {}
