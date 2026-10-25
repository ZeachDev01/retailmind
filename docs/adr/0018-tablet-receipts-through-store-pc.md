# Tablet receipt printing through the Store PC

The owner selected receipt printing from iPad checkout through the thermal printer connected to the Store PC on the same local network, while preserving the existing tablet POS layout and receipt preview. Provide an explicit direct-print action through that PC rather than relying on the tablet browser to select the PC's USB printer; this reuses the Store's printer but requires a PC-side printing path and an available host.

Printing operates on an already saved receipt: a failed or retried print must never repeat checkout or deduct stock again, and uncertain delivery requires a warning before reprinting. The destination and behavior are confirmed; bridge technology, installation, security, and physical iPad/Android verification remain pending. See the [device optimization brief](../specs/role-device-optimization.md). This decision does not claim implemented support or authorize deployment.
