import React, { useMemo } from 'react';
import authentication, { AuthParams } from '@/utils/authentication';
import { useAdminAuth } from '@/auth/AuthContext';
import { toUserPermission } from '@/routes';

type PermissionWrapperProps = AuthParams & {
  backup?: React.ReactNode;
};

const PermissionWrapper = (
  props: React.PropsWithChildren<PermissionWrapperProps>
) => {
  const { backup, requiredPermissions, oneOfPerm } = props;
  const { permissions } = useAdminAuth();
  const userPermission = useMemo(() => toUserPermission(permissions), [permissions]);

  const hasPermission = useMemo(() => {
    return authentication(
        {requiredPermissions, oneOfPerm},
        userPermission
    );
  },[oneOfPerm, requiredPermissions, userPermission]);

  if (hasPermission) {
    return <>{convertReactElement(props.children)}</>;
  }
  if (backup) {
    return <>{convertReactElement(backup)}</>;
  }
  return null;
};

function convertReactElement(node: React.ReactNode): React.ReactElement {
  if (!React.isValidElement(node)) {
    return <>{node}</>;
  }
  return node;
}

export default PermissionWrapper;
