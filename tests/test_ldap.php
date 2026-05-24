<?php
declare(strict_types=1);

use Trumpet\Ldap;

test('ldap: normalizeDn lowercases and trims commas', function () {
    assert_eq('cn=admins,ou=groups,dc=corp,dc=local',
        Ldap::normalizeDn('CN=Admins, OU=Groups , DC=corp,DC=local'));
});

test('ldap: userInAllowedGroups matches case-insensitively', function () {
    $member = ['CN=TrumpetAdmins,OU=Groups,DC=corp,DC=local'];
    $allowed = ['cn=trumpetadmins,ou=groups,dc=corp,dc=local'];
    assert_true(Ldap::userInAllowedGroups($member, $allowed));
});

test('ldap: userInAllowedGroups returns false when no match', function () {
    $member = ['CN=Users,OU=Groups,DC=corp,DC=local'];
    $allowed = ['CN=TrumpetAdmins,OU=Groups,DC=corp,DC=local'];
    assert_eq(false, Ldap::userInAllowedGroups($member, $allowed));
});

test('ldap: userInAllowedGroups with empty allowed list = deny', function () {
    assert_eq(false, Ldap::userInAllowedGroups(['CN=Anything,DC=x'], []));
});

test('ldap: userInAllowedGroups with multi-valued memberOf', function () {
    $member = [
        'CN=Users,OU=Groups,DC=corp,DC=local',
        'CN=TrumpetAdmins,OU=Groups,DC=corp,DC=local',
        'CN=DomainUsers,DC=corp,DC=local',
    ];
    $allowed = ['CN=TrumpetAdmins,OU=Groups,DC=corp,DC=local'];
    assert_true(Ldap::userInAllowedGroups($member, $allowed));
});

test('ldap: parseGroupList single DN (no `;`)', function () {
    $r = Ldap::parseGroupList('CN=TrumpetAdmins,OU=Groups,DC=corp,DC=local');
    assert_eq(['CN=TrumpetAdmins,OU=Groups,DC=corp,DC=local'], $r);
});

test('ldap: parseGroupList multiple DNs separated by `;`', function () {
    $r = Ldap::parseGroupList('CN=A,DC=x ; CN=B,DC=y');
    assert_eq(['CN=A,DC=x', 'CN=B,DC=y'], $r);
});

test('ldap: parseGroupList empty string', function () {
    assert_eq([], Ldap::parseGroupList(''));
});

test('ldap: extractMemberOf handles lowercase key', function () {
    $entry = [
        'dn' => 'cn=u,dc=x',
        'memberof' => ['count' => 2, 0 => 'CN=A,DC=x', 1 => 'CN=B,DC=x'],
        'count' => 2,
    ];
    assert_eq(['CN=A,DC=x', 'CN=B,DC=x'], Ldap::extractMemberOf($entry));
});

test('ldap: extractMemberOf returns empty when missing', function () {
    assert_eq([], Ldap::extractMemberOf(['dn' => 'cn=u,dc=x']));
});

test('ldap: tlsFromUrl detects ldaps scheme', function () {
    assert_eq(true, Ldap::tlsFromUrl('ldaps://dc.corp.local:636'));
    assert_eq(true, Ldap::tlsFromUrl(' LDAPS://x '));
    assert_eq(false, Ldap::tlsFromUrl('ldap://dc.corp.local:389'));
});
