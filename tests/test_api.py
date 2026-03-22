#!/usr/bin/env python3
"""
SYR Device Emulator - Python Test Client

Tests the JSON API using aiohttp (same as Home Assistant integration).
"""

import asyncio
import sys
from typing import Any

try:
    import aiohttp
except ImportError:
    print("ERROR: aiohttp not installed. Install with: pip install aiohttp")
    sys.exit(1)


class SyrEmulatorTest:
    """Test client for SYR device emulator."""

    def __init__(self, base_url: str = "http://localhost:5333", device: str = "neosoft"):
        """Initialize test client."""
        self.base_url = base_url.rstrip("/")
        self.device = device
        self.session: aiohttp.ClientSession | None = None

    async def __aenter__(self):
        """Async context manager entry."""
        self.session = aiohttp.ClientSession()
        return self

    async def __aexit__(self, *args):
        """Async context manager exit."""
        if self.session:
            await self.session.close()

    async def login(self) -> dict[str, Any]:
        """Test login endpoint."""
        url = f"{self.base_url}/{self.device}/set/ADM/(2)f"
        print(f"🔐 Testing login: {url}")

        async with self.session.get(url) as resp:
            data = await resp.json()
            print(f"   Status: {resp.status}")
            print(f"   Response: {data}")
            return data

    async def get_all(self) -> dict[str, Any]:
        """Test GET all values."""
        url = f"{self.base_url}/{self.device}/get/all"
        print(f"\n📡 Testing GET all: {url}")

        async with self.session.get(url) as resp:
            data = await resp.json()
            print(f"   Status: {resp.status}")
            print(f"   Keys: {len(data)}")
            print("   Sample data:")
            for key in list(data.keys())[:5]:
                print(f"     - {key}: {data[key]}")
            return data

    async def get_single(self, key: str) -> dict[str, Any]:
        """Test GET single value."""
        url = f"{self.base_url}/{self.device}/get/{key}"
        print(f"\n🔍 Testing GET single: {url}")

        async with self.session.get(url) as resp:
            data = await resp.json()
            print(f"   Status: {resp.status}")
            print(f"   Response: {data}")
            return data

    async def set_value(self, key: str, value: str) -> dict[str, Any]:
        """Test SET operation."""
        url = f"{self.base_url}/{self.device}/set/{key}/{value}"
        print(f"\n⚙️  Testing SET: {url}")

        async with self.session.get(url) as resp:
            data = await resp.json()
            print(f"   Status: {resp.status}")
            print(f"   Response: {data}")
            return data

    async def run_tests(self):
        """Run all tests."""
        print("=" * 60)
        print("SYR Device Emulator - Python/aiohttp Test Client")
        print("=" * 60)
        print(f"Base URL: {self.base_url}")
        print(f"Device:   {self.device}\n")

        try:
            # Test 1: Login
            await self.login()

            # Test 2: GET all
            initial_data = await self.get_all()

            # Test 3: GET single values
            print("\n" + "=" * 60)
            print("Testing GET Single Values")
            print("=" * 60)
            
            # Test existing keys
            for key in ["AB", "FLO", "SV1", "RPD"]:
                result = await self.get_single(key)
                get_key = f"get{key}"
                if get_key in result and result[get_key] != "NSC":
                    print(f"   ✓ {key}: {result[get_key]} (exists)")
                else:
                    print(f"   ✗ {key}: Unexpected response {result}")
            
            # Test non-existing keys (should return NSC)
            for invalid_key in ["XYZ", "INVALID", "TEST123"]:
                result = await self.get_single(invalid_key)
                get_key = f"get{invalid_key}"
                if get_key in result and result[get_key] == "NSC":
                    print(f"   ✓ {invalid_key}: NSC (not found)")
                else:
                    print(f"   ✗ {invalid_key}: Expected NSC, got {result}")

            # Test 4: SET operations
            print("\n" + "=" * 60)
            print("Testing SET Operations")
            print("=" * 60)

            # SET valve
            await self.set_value("AB", "true")

            # SET regeneration time
            await self.set_value("RTM", "03:30")

            # SET salt amount
            await self.set_value("SV1", "25")

            # SET regeneration interval (valid range 1-3)
            await self.set_value("RPD", "3")

            # Test 5: Validation tests (MIMA response)
            print("\n" + "=" * 60)
            print("Testing Validation (MIMA Responses)")
            print("=" * 60)
            
            # Test valid RPD values (should return OK)
            for valid_value in ["1", "2", "3"]:
                result = await self.set_value("RPD", valid_value)
                expected_key = f"setRPD{valid_value}"
                if expected_key in result and result[expected_key] == "OK":
                    print(f"   ✓ RPD={valid_value}: OK (valid)")
                else:
                    print(f"   ✗ RPD={valid_value}: Unexpected response {result}")
            
            # Test invalid RPD values (should return MIMA)
            for invalid_value in ["0", "4", "99"]:
                result = await self.set_value("RPD", invalid_value)
                expected_key = f"setRPD{invalid_value}"
                if expected_key in result and result[expected_key] == "MIMA":
                    print(f"   ✓ RPD={invalid_value}: MIMA (out of range)")
                else:
                    print(f"   ✗ RPD={invalid_value}: Unexpected response {result}")

            # Test 6: Verify response format
            print("\n" + "=" * 60)
            print("Verifying Response Format")
            print("=" * 60)
            
            # Test response key format: set + KEY + VALUE
            result = await self.set_value("SIR", "0")
            if "setSIR0" in result and result["setSIR0"] == "OK":
                print("   ✓ Response format correct: setSIR0 = OK")
            else:
                print(f"   ✗ Response format incorrect: {result}")

            # Test 7: Verify changes
            print("\n" + "=" * 60)
            print("Verifying Changes")
            print("=" * 60)

            updated_data = await self.get_all()

            changes = []
            for key in ["getAB", "getRTM", "getSV1", "getRPD"]:
                old = initial_data.get(key)
                new = updated_data.get(key)
                if old != new:
                    changes.append(f"   ✓ {key}: {old} → {new}")
                else:
                    changes.append(f"   ! {key}: {old} (unchanged)")

            print("Changes detected:")
            for change in changes:
                print(change)

            # Test 8: Error handling
            print("\n" + "=" * 60)
            print("Testing Error Handling")
            print("=" * 60)

            try:
                await self.set_value("INVALID_KEY", "123")
            except Exception as e:
                print(f"   ✓ Expected error caught: {e}")

            print("\n" + "=" * 60)
            print("✓ All tests completed successfully!")
            print("=" * 60)
            print("\nCheck ../logs/set_operations.log for detailed logs")

        except Exception as e:
            print(f"\n✗ Test failed with error: {e}")
            raise


async def main():
    """Main entry point."""
    base_url = sys.argv[1] if len(sys.argv) > 1 else "http://localhost:5333"
    device = sys.argv[2] if len(sys.argv) > 2 else "neosoft"

    if device not in ["neosoft", "trio"]:
        print(f"Error: Invalid device '{device}'. Use: neosoft or trio")
        sys.exit(1)

    async with SyrEmulatorTest(base_url, device) as test:
        await test.run_tests()


if __name__ == "__main__":
    asyncio.run(main())
